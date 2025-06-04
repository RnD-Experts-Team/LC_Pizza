<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTimeLoadedIntoPortalToDetailOrdersTable extends Migration
{
    public function up()
    {
        Schema::table('detail_orders', function (Blueprint $table) {
            $table->dateTime('time_loaded_into_portal')->nullable()->after('portal_compartments_used');
        });
    }

    public function down()
    {
        Schema::table('detail_orders', function (Blueprint $table) {
            $table->dropColumn('time_loaded_into_portal');
        });
    }
}
