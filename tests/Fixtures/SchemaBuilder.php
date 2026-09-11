<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Tests\Fixtures;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SchemaBuilder
{
    public static function migrate(string $connection): void
    {
        $schema = Schema::connection($connection);

        $schema->create('companies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('stripe_id')->nullable();
        });

        $schema->create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('failed_job_id')->nullable();
        });

        $schema->create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
        });

        $schema->create('orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->decimal('total', 10, 2);
            $table->dateTime('created_at')->nullable();

            $table->foreign('company_id')->references('id')->on('companies');
        });

        $schema->create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('sku');
        });

        $schema->create('countries', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
        });

        $schema->create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->json('payload');
        });

        $schema->create('comments', function (Blueprint $table): void {
            $table->id();
            $table->string('commentable_type');
            $table->unsignedBigInteger('commentable_id');
            $table->text('body');
        });
    }

    public static function seedTwoCompanies(string $connection): void
    {
        $db = DB::connection($connection);

        $db->table('companies')->insert([
            ['id' => 1, 'name' => 'Acme', 'stripe_id' => 'cus_1'],
            ['id' => 2, 'name' => 'Globex', 'stripe_id' => null],
        ]);

        $db->table('users')->insert([
            ['id' => 10, 'company_id' => 1, 'email' => 'a@acme.test', 'password' => 'secret', 'created_by' => null, 'failed_job_id' => null],
            ['id' => 50, 'company_id' => 2, 'email' => 'b@globex.test', 'password' => 'secret', 'created_by' => null, 'failed_job_id' => 1],
            ['id' => 60, 'company_id' => 2, 'email' => 'c@globex.test', 'password' => 'secret', 'created_by' => 50, 'failed_job_id' => null],
        ]);

        $db->table('customers')->insert([
            ['id' => 7, 'email' => 'seven@example.test'],
            ['id' => 8, 'email' => 'eight@example.test'],
        ]);

        $db->table('orders')->insert([
            ['id' => 1, 'company_id' => 1, 'user_id' => 50, 'customer_id' => 7, 'total' => 10.00, 'created_at' => '2026-01-15 00:00:00'],
            ['id' => 2, 'company_id' => 1, 'user_id' => 10, 'customer_id' => null, 'total' => 20.00, 'created_at' => '2026-06-15 00:00:00'],
            ['id' => 99, 'company_id' => 2, 'user_id' => 50, 'customer_id' => 8, 'total' => 30.00, 'created_at' => '2026-03-01 00:00:00'],
        ]);

        $db->table('order_items')->insert([
            ['id' => 1, 'order_id' => 1, 'sku' => 'SKU-1'],
            ['id' => 2, 'order_id' => 1, 'sku' => 'SKU-2'],
            ['id' => 3, 'order_id' => 99, 'sku' => 'SKU-3'],
        ]);

        $db->table('countries')->insert([
            ['id' => 1, 'code' => 'US'],
            ['id' => 2, 'code' => 'CA'],
        ]);

        $db->table('failed_jobs')->insert([
            ['id' => 1, 'payload' => '{}'],
        ]);
    }
}
