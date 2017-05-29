<?php

namespace InfyOm\Generator\Generators;

use File;
use Illuminate\Support\Str;
use InfyOm\Generator\Common\CommandData;
use InfyOm\Generator\Utils\FileUtil;
use SplFileInfo;

class MigrationGenerator extends BaseGenerator
{
    /** @var CommandData */
    private $commandData;

    /** @var string */
    private $path;

    public function __construct($commandData)
    {
        $this->commandData = $commandData;
        $this->path        = config('infyom.laravel_generator.path.migration', base_path('database/migrations/'));
    }

    public function generate()
    {
        $templateData = get_template('migration', 'laravel-generator');

        $templateData = fill_template($this->commandData->dynamicVars, $templateData);

        $templateData = str_replace('$FIELDS$', $this->generateFields(), $templateData);
        $templateData = str_replace('$FOREIGN_FIELDS$', $this->generateForeignFields(), $templateData);
        $templateData = str_replace('$USE_MODELS$', $this->getUseModels(), $templateData);

        $tableName = $this->commandData->dynamicVars['$TABLE_NAME$'];

        $fileName = date('Y_m_d_His') . '_' . 'create_' . $tableName . '_table.php';

        FileUtil::createFile($this->path, $fileName, $templateData);

        $this->commandData->commandComment("\nMigration created: ");
        $this->commandData->commandInfo($fileName);
    }

    private function generateFields()
    {
        $fields         = [];
        $foreignKeys    = [];
        $createdAtField = null;
        $updatedAtField = null;

        foreach ($this->commandData->fields as $field) {
            if ($field->name == 'created_at') {
                $createdAtField = $field;
                continue;
            } else {
                if ($field->name == 'updated_at') {
                    $updatedAtField = $field;
                    continue;
                }
            }

            if ($field->name == 'id')
                continue;

            $migrationText  = $field->migrationText;
            $fieldName      = $field->name;
            $fieldNameUpper = strtoupper($fieldName);
            $migrationText  = str_replace("'$fieldName'", "Model::FIELD_$fieldNameUpper", $migrationText);
            $migrationText  = str_replace(";", "->comment('');", $migrationText);

            if (!empty($field->foreignKeyText)) {
                continue;
//                $foreignKeys[] = $field->foreignKeyText;
//                $migrationText = str_replace('integer', 'bigInteger', $migrationText);
            }

            $fields[] = $migrationText;
        }

        /*if ($createdAtField and $updatedAtField) {
            $fields[] = '$table->timestamps();';
        } else {
            if ($createdAtField) {
                $fields[] = $createdAtField->migrationText;
            }
            if ($updatedAtField) {
                $fields[] = $updatedAtField->migrationText;
            }
        }*/

        /*if ($this->commandData->getOption('softDelete')) {
            $fields[] = '$table->softDeletes();';
        }*/

        return implode(infy_nl_tab(1, 3), array_merge($fields, $foreignKeys));
    }

    private function generateForeignFields()
    {
        $fields         = [];
        $foreignKeys    = [];
        $createdAtField = null;
        $updatedAtField = null;

        foreach ($this->commandData->fields as $field) {
            if ($field->name == 'created_at') {
                $createdAtField = $field;
                continue;
            } else {
                if ($field->name == 'updated_at') {
                    $updatedAtField = $field;
                    continue;
                }
            }

            if (!empty($field->foreignKeyText)) {
                $migrationText  = $field->migrationText;
                $fieldName      = $field->name;
                $fieldNameUpper = strtoupper($fieldName);
                $migrationText  = str_replace("'$fieldName'", "Model::FIELD_$fieldNameUpper", $migrationText);
                $migrationText  = str_replace(";", "->comment('');", $migrationText);
                $migrationText  = str_replace('integer', 'bigInteger', $migrationText);
                $fields[]       = $migrationText;

                $foreignKeyText   = $field->foreignKeyText;
                $foreignTableName = substr($foreignKeyText, strpos($foreignKeyText, "on('") + strlen("on('"), strpos($foreignKeyText, "');") - strpos($foreignKeyText, "on('") - strlen("on('"));
                $foreignModelName = ucfirst(str_singular($foreignTableName));
                $foreignKeyText   = str_replace("'$fieldName'", "Model::FIELD_" . strtoupper(ends_with($fieldName, '_id') ? $fieldName : "{$fieldName}_id"), $foreignKeyText);
                $foreignKeyText   = str_replace("'id'", "$foreignModelName::FIELD_ID", $foreignKeyText);
                $foreignKeyText   = str_replace("'$foreignTableName'", "$foreignModelName::TABLE_NAME", $foreignKeyText);
                $foreignKeyText   = str_replace(";", "->onDelete('cascade');", $foreignKeyText);

                $foreignKeys[] = $foreignKeyText;
            }
        }

        return implode(infy_nl_tab(1, 3), array_merge($fields, $foreignKeys));
    }

    private function getUseModels()
    {
        $useArray = [];

        $namespaceModel = $this->commandData->dynamicVars['$NAMESPACE_MODEL$'];
        $modelName      = $this->commandData->dynamicVars['$MODEL_NAME$'];
        $useArray[]     = "use $namespaceModel\\$modelName as Model;";

        foreach ($this->commandData->fields as $field) {
            if ($field->name == 'created_at')
                continue;
            elseif ($field->name == 'updated_at')
                continue;

            if (!empty($field->foreignKeyText)) {
                $fieldName  = $field->name;
                $useArray[] = "use $namespaceModel\\" . ucfirst(substr($fieldName, 0, strpos($fieldName, '_id'))) . ';';
            }
        }

        return implode('' . infy_nl_tab(1, 0), $useArray);
    }

    public function rollback()
    {
        $fileName = 'create_' . $this->commandData->config->tableName . '_table.php';

        /** @var SplFileInfo $allFiles */
        $allFiles = File::allFiles($this->path);

        $files = [];

        foreach ($allFiles as $file) {
            $files[] = $file->getFilename();
        }

        $files = array_reverse($files);

        foreach ($files as $file) {
            if (Str::contains($file, $fileName)) {
                if ($this->rollbackFile($this->path, $file)) {
                    $this->commandData->commandComment('Migration file deleted: ' . $file);
                }
                break;
            }
        }
    }
}
