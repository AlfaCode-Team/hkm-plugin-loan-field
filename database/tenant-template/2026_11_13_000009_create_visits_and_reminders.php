<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Schema\Blueprint;

/**
 * Field work: who was visited, and who was reminded.
 */
return new class implements MigrationInterface {

    public function up($schema): void
    {
        $schema->create('visits', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('loan_id')->nullable();
            $table->string('visit_type', 40);                     // payment_collection | new_client | …
            $table->date('visited_on');
            $table->dateTime('visited_at')->nullable();
            $table->string('outcome', 120)->nullable();
            $table->text('notes')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('photo_path', 255)->nullable();
            $table->unsignedBigInteger('recorded_by');

            $table->unsignedBigInteger('rev')->default(0);
            $table->string('device_id', 64)->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->dateTime('deleted_at')->nullable();

            $table->index(['rev'], 'idx_visits_rev');
            $table->index(['customer_id'], 'idx_visits_customer');
            $table->index(['recorded_by', 'visited_on'], 'idx_visits_by_date');
        });

        // A nudge that was ATTEMPTED. The app hands off to WhatsApp, Messages
        // or the dialler, so this records the attempt — the phone owns delivery.
        // A logged phone call is a reminder too: it is the same nudge by another
        // means, and treating it as one is what lets a screen say "already
        // called today".
        $schema->create('reminders', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('loan_id')->nullable();
            $table->string('channel', 16);                        // whatsapp | sms | call
            $table->unsignedBigInteger('amount_quoted')->nullable();
            $table->string('message', 1000)->nullable();
            $table->dateTime('sent_at');
            $table->unsignedBigInteger('sent_by');

            $table->unsignedBigInteger('rev')->default(0);
            $table->string('device_id', 64)->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->dateTime('deleted_at')->nullable();

            $table->index(['rev'], 'idx_reminders_rev');
            $table->index(['customer_id', 'sent_at'], 'idx_reminders_customer');
        });
    }

    public function down($schema): void
    {
        $schema->dropIfExists('reminders');
        $schema->dropIfExists('visits');
    }
};
