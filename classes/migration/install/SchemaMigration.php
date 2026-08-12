<?php

/**
 * @file plugins/generic/recommendByAuthor/classes/migration/install/SchemaMigration.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SchemaMigration
 *
 * @brief The three tables that let the article page read its recommendations
 *        instead of computing them.
 */

namespace APP\plugins\generic\recommendByAuthor\classes\migration\install;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class SchemaMigration extends Migration
{
    public function up(): void
    {
        // One row per (author identity, published submission). The identity is a
        // normalised key -- an ORCID or a normalised name -- so that finding the
        // articles of an author is an index lookup instead of a scan over
        // author_settings. An author yields more than one key when the name is
        // recorded in several locales, or when an ORCID is on file as well.
        Schema::create('recommend_author_index', function (Blueprint $table) {
            $table->string('author_key', 160);
            $table->bigInteger('submission_id');
            $table->bigInteger('context_id');

            $table->primary(['author_key', 'submission_id'], 'recommend_author_index_pkey');
            $table->index(['submission_id'], 'recommend_author_index_submission_id');
            $table->index(['context_id'], 'recommend_author_index_context_id');

            $table->foreign('submission_id', 'recommend_author_index_submission_id_fk')
                ->references('submission_id')->on('submissions')->onDelete('cascade');
        });

        // The materialised recommendations: the ordered list of submissions to
        // show under a given submission. Read by primary key, never computed on
        // the page.
        Schema::create('recommend_author_cache', function (Blueprint $table) {
            $table->bigInteger('submission_id');
            $table->smallInteger('seq');
            $table->bigInteger('recommended_submission_id');

            $table->primary(['submission_id', 'seq'], 'recommend_author_cache_pkey');

            $table->foreign('submission_id', 'recommend_author_cache_submission_id_fk')
                ->references('submission_id')->on('submissions')->onDelete('cascade');
            $table->foreign('recommended_submission_id', 'recommend_author_cache_recommended_fk')
                ->references('submission_id')->on('submissions')->onDelete('cascade');
            $table->index(['recommended_submission_id'], 'recommend_author_cache_recommended');
        });

        // What has been indexed and computed, and when.
        //
        // The two are separate on purpose. The recommendations of an article
        // can only name articles that are already in the index, so computing
        // them while the index is still being filled would store lists that
        // are quietly incomplete -- and store them as if they were finished.
        // Indexing therefore runs to completion first; only then does anything
        // get computed. A null computed_at means "queued": the scheduled task
        // takes those first, then the ones computed longest ago. The version
        // stamp invalidates the rendered-HTML cache without having to
        // enumerate its keys (one per locale and page).
        Schema::create('recommend_author_state', function (Blueprint $table) {
            $table->bigInteger('submission_id');
            $table->bigInteger('context_id');
            $table->datetime('indexed_at')->nullable();
            $table->datetime('computed_at')->nullable();
            $table->bigInteger('version')->default(1);

            $table->primary(['submission_id'], 'recommend_author_state_pkey');
            $table->index(['context_id', 'computed_at'], 'recommend_author_state_queue');
            $table->index(['indexed_at'], 'recommend_author_state_indexed');

            $table->foreign('submission_id', 'recommend_author_state_submission_id_fk')
                ->references('submission_id')->on('submissions')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::drop('recommend_author_cache');
        Schema::drop('recommend_author_state');
        Schema::drop('recommend_author_index');
    }
}
