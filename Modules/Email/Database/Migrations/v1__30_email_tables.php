<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        MigrationHelper::table('EmailTemplate', function (Blueprint $table): void {
            $table->string('ModuleTag', 40);
            $table->string('Code', 80);
            $table->string('Subject', 250);
            $table->text('BodyHtml');
            $table->text('BodyText');
            $table->text('VariablesJson');
            $table->boolean('IsActive')->default(true);
            $table->integer('Version')->default(1);
        });
        MigrationHelper::naturalKey('EmailTemplate', ['BranchId', 'Code']);
        MigrationHelper::addRowVersion('EmailTemplate');
        MigrationHelper::addSoftDeletes('EmailTemplate');

        // Immutable full snapshots, including version 1; disabling retains history.
        MigrationHelper::table('EmailTemplateVersion', function (Blueprint $table): void {
            $table->bigInteger('TemplateId');
            $table->string('ModuleTag', 40);
            $table->string('Code', 80);
            $table->integer('Version');
            $table->string('Subject', 250);
            $table->text('BodyHtml');
            $table->text('BodyText');
            $table->text('VariablesJson');
            $table->boolean('IsActive');
        });
        MigrationHelper::naturalKey('EmailTemplateVersion', ['BranchId', 'TemplateId', 'Version']);
        MigrationHelper::recordVersion('1.46', 'Module-owned email templates, branch overrides and version history');
    }

    public function down(): void
    {
        MigrationHelper::drop('EmailTemplateVersion');
        MigrationHelper::drop('EmailTemplate');
    }
};
