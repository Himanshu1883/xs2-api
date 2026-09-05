<?php

namespace App\Support;

use App\Models\Xs2Order;

/**
 * Helpers for XS2 bookingorder identifiers, including local sb-pending placeholders.
 */
final class Xs2BookingOrderIdentity
{
    public const PENDING_EXTERNAL_ORDER_PREFIX = 'sb-pending:';

    public static function isPendingExternalOrderId(?string $id): bool
    {
        $value = self::nullableString($id);

        return $value !== null
            && str_starts_with($value, self::PENDING_EXTERNAL_ORDER_PREFIX);
    }

    public static function pendingExternalOrderId(string $bookingNo): string
    {
        return self::PENDING_EXTERNAL_ORDER_PREFIX.$bookingNo;
    }

    public static function resolvedBookingOrderId(?string $xs2BookingOrderId, ?string $externalOrderId): ?string
    {
        foreach ([$xs2BookingOrderId, $externalOrderId] as $candidate) {
            $value = self::nullableString($candidate);
            if ($value !== null && ! self::isPendingExternalOrderId($value)) {
                return $value;
            }
        }

        return null;
    }

    public static function hasResolvableBookingOrderId(?string $xs2BookingOrderId, ?string $externalOrderId): bool
    {
        return self::resolvedBookingOrderId($xs2BookingOrderId, $externalOrderId) !== null;
    }

    public static function orderHasResolvableBookingOrderId(Xs2Order $order): bool
    {
        return self::hasResolvableBookingOrderId(
            self::nullableString($order->xs2_bookingorder_id),
            self::nullableString($order->external_order_id),
        );
    }

    public static function orderHasPendingBookingOrderId(Xs2Order $order): bool
    {
        return self::isPendingExternalOrderId(self::nullableString($order->xs2_bookingorder_id))
            || self::isPendingExternalOrderId(self::nullableString($order->external_order_id));
    }

    public static function pendingTicketMessage(Xs2Order $order): string
    {
        if ($order->sb_order_id !== null) {
            return 'Complete Create manual first to obtain the real XS2 bookingorder_id.';
        }

        return 'Sync order from XS2 Production API to obtain the real bookingorder_id.';
    }

    private static function nullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim($value);

        return $string === '' ? null : $string;
    }
}
