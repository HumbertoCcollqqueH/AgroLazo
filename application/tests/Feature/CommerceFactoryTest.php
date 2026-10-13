<?php

namespace Tests\Feature;

use App\Models;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CommerceFactoryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_synthetic_factories_preserve_receipt_origin_and_financial_foreign_keys(): void
    {
        config(['producers.lookup_key' => str_repeat('a', 64)]);
        $this->seed(RolesAndPermissionsSeeder::class);
        $item = Models\ShipmentItem::factory()->create();
        $receipt = $item->physicalItem->receipt;
        $this->assertSame($receipt->event->order_id, $item->shipment->order_id);
        $this->assertSame($receipt->event->collection_point_id, $item->physicalItem->physicalLot->collection_point_id);
        $this->assertSame($receipt->allocation->portion->lot_id, $item->physicalItem->physicalLot->lot_id);
        $line = Models\ProducerSettlementItem::factory()->create();
        $this->assertSame($line->receipt->allocation->agreement_id, $line->agreement_id);
        $this->assertSame($line->receipt->event->order_id, $line->settlement->order_id);
        $this->assertSame($line->gross_minor - $line->agreed_cost_minor, $line->net_minor);
        $refund = Models\PaymentRefund::factory()->create();
        $this->assertSame('confirmed', $refund->payment->status);
        $this->assertLessThanOrEqual($refund->payment->amount_minor, $refund->amount_minor);
        $payout = Models\ProducerPayout::factory()->create();
        $this->assertSame($payout->settlement->order_id, $payout->order_id);
        $this->assertSame($payout->settlement->producer_id, $payout->producer_id);
        $adjustment = Models\OrderAdjustmentItem::factory()->create();
        $this->assertSame($adjustment->allocation->orderItem->order_id, $adjustment->adjustment->order_id);
        $reservation = Models\ReservationItem::factory()->create();
        $this->assertSame($reservation->quoteItem->quote_id, $reservation->reservation->quote_id);
    }
}
