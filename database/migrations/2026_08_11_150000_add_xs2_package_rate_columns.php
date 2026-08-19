<?php

use App\Models\Xs2Ticket;
use App\Services\Xs2\Xs2PackageRateService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('xs2_tickets', function (Blueprint $table): void {
            if (! Schema::hasColumn('xs2_tickets', 'is_package_rate')) {
                $table->boolean('is_package_rate')->default(false)->after('flags');
            }
            if (! Schema::hasColumn('xs2_tickets', 'package_quantity')) {
                $table->unsignedInteger('package_quantity')->nullable()->after('is_package_rate');
            }
            if (! Schema::hasColumn('xs2_tickets', 'package_price')) {
                $table->unsignedBigInteger('package_price')->nullable()->after('package_quantity');
            }
        });

        if (! Schema::hasTable('xs2_tickets')) {
            return;
        }

        $resolver = app(Xs2PackageRateService::class);

        Xs2Ticket::query()
            ->whereJsonContains('flags', 'package_rate')
            ->orderBy('id')
            ->chunkById(200, function ($tickets) use ($resolver): void {
                foreach ($tickets as $ticket) {
                    $resolved = $resolver->resolveFromPayload(is_array($ticket->raw_payload) ? $ticket->raw_payload : []);
                    $ticket->update([
                        'is_package_rate' => $resolved['is_package_rate'],
                        'package_quantity' => $resolved['package_quantity'],
                        'package_price' => $resolved['package_price'],
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('xs2_tickets', function (Blueprint $table): void {
            if (Schema::hasColumn('xs2_tickets', 'package_price')) {
                $table->dropColumn('package_price');
            }
            if (Schema::hasColumn('xs2_tickets', 'package_quantity')) {
                $table->dropColumn('package_quantity');
            }
            if (Schema::hasColumn('xs2_tickets', 'is_package_rate')) {
                $table->dropColumn('is_package_rate');
            }
        });
    }
};
