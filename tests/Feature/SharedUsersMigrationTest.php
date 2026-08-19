<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SharedUsersMigrationTest extends TestCase
{
    public function test_the_foundational_migration_does_not_own_the_shared_users_table(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('shared_identity_marker');
        });

        DB::table('users')->insert([
            'id' => 42,
            'shared_identity_marker' => 'preserve-me',
        ]);

        $migration = require database_path('migrations/0001_01_01_000000_create_users_table.php');

        $migration->up();

        $this->assertTrue(Schema::hasTable('users'));
        $this->assertSame('preserve-me', DB::table('users')->where('id', 42)->value('shared_identity_marker'));
        $this->assertTrue(Schema::hasTable('password_reset_tokens'));
        $this->assertTrue(Schema::hasTable('sessions'));

        $migration->down();

        $this->assertTrue(Schema::hasTable('users'));
        $this->assertSame('preserve-me', DB::table('users')->where('id', 42)->value('shared_identity_marker'));
        $this->assertFalse(Schema::hasTable('password_reset_tokens'));
        $this->assertFalse(Schema::hasTable('sessions'));
    }
}
