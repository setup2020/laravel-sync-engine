<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('sync_histories', function (Blueprint $table) {
            $table->id();
            $table->string('client_id')->nullable();
            $table->enum('type', ['push', 'pull']);
            $table->string('table');
            $table->integer('records_count')->default(0);
            $table->string('status')->default('success');
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('sync_histories');
    }
};
