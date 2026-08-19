<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Xs2SandboxTestOrder extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SB_ORDER_CREATED = 'sb_order_created';

    public const STATUS_XS2_ORDER_CREATED = 'xs2_order_created';

    public const STATUS_FAILED = 'failed';

    public const ENVIRONMENT = 'sandbox';

    protected $fillable = [
        'seatsbroker_order_id',
        'environment',
        'is_sandbox',
        'status',
        'customer_name',
        'customer_email',
        'quantity',
        'xs2_event_id',
        'xs2_event_payload',
        'xs2_ticket_id',
        'xs2_ticket_payload',
        'xs2_reservation_id',
        'xs2_booking_id',
        'xs2_bookingorder_id',
        'xs2_booking_code',
        'xs2_reservation_request',
        'xs2_reservation_response',
        'xs2_booking_request',
        'xs2_booking_response',
        'xs2_guest_data_request',
        'xs2_guest_data_response',
        'xs2_eticket_request',
        'xs2_eticket_response',
        'last_error',
        'sb_order_created_at',
        'xs2_order_created_at',
    ];

    protected function casts(): array
    {
        return [
            'is_sandbox' => 'boolean',
            'quantity' => 'integer',
            'xs2_event_payload' => 'array',
            'xs2_ticket_payload' => 'array',
            'xs2_reservation_request' => 'array',
            'xs2_reservation_response' => 'array',
            'xs2_booking_request' => 'array',
            'xs2_booking_response' => 'array',
            'xs2_guest_data_request' => 'array',
            'xs2_guest_data_response' => 'array',
            'xs2_eticket_request' => 'array',
            'xs2_eticket_response' => 'array',
            'sb_order_created_at' => 'datetime',
            'xs2_order_created_at' => 'datetime',
        ];
    }

    public function hasXs2Order(): bool
    {
        return filled($this->xs2_reservation_id) || filled($this->xs2_booking_id);
    }
}
