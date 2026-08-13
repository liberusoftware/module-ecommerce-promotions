<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Liberu\Ecommerce\Promotions\Enums\ReleaseReason;
use Liberu\Ecommerce\Promotions\Events\RedemptionReleased;
use Liberu\Ecommerce\Promotions\Exceptions\RedemptionAlreadyReleased;
use Liberu\Ecommerce\Promotions\Models\Offer;
use Liberu\Ecommerce\Promotions\Models\Redemption;
use Liberu\Ecommerce\Promotions\Models\RedemptionRelease;

/**
 * Give a use back.
 *
 * The host cannot do this at all: it counts orders, so a cancelled order spends a
 * coupon forever and there is no way to return one. A release is its own
 * append-only record — not a deletion and not a status flip — so "spent then
 * returned" and "never spent" stay distinguishable, and the unique index on
 * `redemption_id` is what makes it happen at most once.
 *
 * The counter comes back down by the same conditional update that claimed it.
 */
final class ReleaseRedemption
{
    public function __invoke(
        int $redemptionId,
        ReleaseReason $reason,
        ?string $actorRef = null,
        ?string $note = null,
        ?CarbonImmutable $occurredAt = null,
    ): RedemptionRelease {
        $occurredAt ??= CarbonImmutable::now();

        return DB::transaction(function () use ($redemptionId, $reason, $actorRef, $note, $occurredAt): RedemptionRelease {
            $redemption = Redemption::query()->findOrFail($redemptionId);

            try {
                $release = RedemptionRelease::query()->create([
                    'redemption_id' => $redemption->id,
                    'reason' => $reason,
                    'actor_ref' => $actorRef,
                    'note' => $note,
                    'occurred_at' => $occurredAt,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw RedemptionAlreadyReleased::redemption($redemption->id);
            }

            // The per-customer slot goes back. It is a constraint marker rather
            // than a fact about the world, so clearing it takes nothing away: the
            // redemption, its lines and this release all survive.
            $redemption->forceFill(['customer_sequence' => null])->save();

            // Guarded so it cannot go below zero. Zero affected rows can only mean
            // the counter was already at zero, which the recompute query is there
            // to notice.
            Offer::query()
                ->whereKey($redemption->offer_id)
                ->where('redemptions_used', '>', 0)
                ->decrement('redemptions_used');

            Event::dispatch(new RedemptionReleased($redemption, $release));

            return $release;
        });
    }
}
