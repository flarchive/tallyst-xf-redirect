<?php

declare(strict_types=1);

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * A small table holding the NON-DERIVABLE part of the map (meta, domains,
 * node→tag, user exceptions). Everything else (thread/post/user identity,
 * post→number) comes from the live Flarum tables.
 */
return Migration::createTable('xf_migration_map', function (Blueprint $table) {
    $table->increments('id');
    $table->string('source_type', 20);          // meta | domain | node | user_x
    $table->unsignedInteger('source_id')->nullable();
    $table->string('target')->nullable();        // base_url | host | tag_slug | flarum_id
    $table->text('extra')->nullable();           // JSON: {xf_per_page,…} / {tag_id}
    $table->index(['source_type', 'source_id']);
});
