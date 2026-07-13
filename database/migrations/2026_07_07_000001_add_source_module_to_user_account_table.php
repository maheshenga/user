<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_account', function (Blueprint $table) {
            $table->string('source_module', 80)->default('core')->index();
        });
    }

    public function down(): void
    {
        Schema::table('user_account', function (Blueprint $table) {
            $table->dropColumn('source_module');
        });
    }
};
