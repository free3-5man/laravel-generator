<?php

namespace InfyOm\Generator\Generators;

use InfyOm\Generator\Common\CommandData;
use InfyOm\Generator\Utils\FileUtil;
use InfyOm\Generator\Utils\TableFieldsGenerator;

class ModelGenerator extends BaseGenerator
{
    /**
     * Fields not included in the generator by default.
     *
     * @var array
     */
    protected $excluded_fields = [
        'created_at',
        'updated_at',
    ];

    /** @var CommandData */
    private $commandData;

    /** @var string */
    private $path;
    private $fileName;
    private $table;

    private $useRelationModels;
    private $relationships;
    private $joiningTables;
    private $foreignIds;

    /**
     * ModelGenerator constructor.
     *
     * @param \InfyOm\Generator\Common\CommandData $commandData
     */
    public function __construct(CommandData $commandData)
    {
        $this->commandData = $commandData;
        $this->path        = $commandData->config->pathModel;
        $this->fileName    = $this->commandData->modelName . '.php';
        $this->table       = $this->commandData->dynamicVars['$TABLE_NAME$'];

        $this->useRelationModels = [];
        $this->relationships     = [];
        $this->joiningTables     = [];
        $this->foreignIds        = [];
    }

    public function generate()
    {
        $templateData = get_template('model.model', 'laravel-generator');

        $templateData = $this->fillTemplate($templateData);

        FileUtil::createFile($this->path, $this->fileName, $templateData);

        $this->commandData->commandComment("\nModel created: ");
        $this->commandData->commandInfo($this->fileName);
    }

    private function fillTemplate($templateData)
    {
        $templateData = fill_template($this->commandData->dynamicVars, $templateData);

        $templateData = $this->fillSoftDeletes($templateData);

        $fillables = [];

        foreach ($this->commandData->fields as $field) {
            if ($field->isFillable && empty($field->foreignKeyText)) {
                $fillables[] = "'" . $field->name . "'";
            }
        }

        $foreignFields = [];

        foreach ($this->commandData->fields as $field) {
            if (!empty($field->foreignKeyText)) {
                $foreignFields[] = "'" . $field->name . "'";
            }
        }

        $templateData = $this->fillDocs($templateData);

        $templateData = $this->fillTimestamps($templateData);

        if ($this->commandData->getOption('primary')) {
            $primary = infy_tab() . "protected \$primaryKey = '" . $this->commandData->getOption('primary') . "';\n";
        } else {
            $primary = '';
        }

        $templateData = str_replace('$PRIMARY$', $primary, $templateData);

        $templateData = str_replace('$FIELDS$', $this->getFields($fillables), $templateData);
        $templateData = str_replace('$FOREIGN_FIELDS$', $this->getForeignFields($foreignFields), $templateData);
        $templateData = str_replace('$FOREIGN_IDS$', $this->getForeignIds($foreignFields), $templateData);

        $templateData = str_replace('$FILLABLE$', $this->getFillable($fillables), $templateData);
        $templateData = str_replace('$TRANSFORM_ALIASES$', $this->getTransformAliases($fillables), $templateData);
        $templateData = str_replace('$MODEL_SINGLE_NAME$', strtolower($this->commandData->modelName), $templateData);

        $templateData = str_replace('$RULES$', implode(',' . infy_nl_tab(1, 2), $this->generateRules()) . ",\n", $templateData);

        $templateData = str_replace('$CAST$', implode(',' . infy_nl_tab(1, 2), $this->generateCasts()) . ",\n", $templateData);

        // 未替换belongsToMany的JOINING_TABLE和self_if
        $relationsTmp = fill_template($this->commandData->dynamicVars, implode(PHP_EOL . infy_nl_tab(1, 1), $this->generateRelations()));
        $relationsTmp = str_replace("'" . strtolower($this->commandData->modelName) . "_id'", 'self::FIELD_FOREIGN_ID', $relationsTmp);
        $templateData = str_replace(
            '$RELATIONS$',
            $relationsTmp,
            $templateData
        );
        $templateData = str_replace('$USE_RELATION_MODEL$', implode('' . infy_nl_tab(1, 0), $this->useRelationModels), $templateData);
        $templateData = str_replace('$RELATIONSHIPS$', implode('' . infy_nl_tab(1, 1), $this->relationships), $templateData);
        $templateData = str_replace('$JOINING_TABLES$', implode('' . infy_nl_tab(1, 1), $this->joiningTables), $templateData);

        $templateData = str_replace('$GENERATE_DATE$', date('F j, Y, g:i a T'), $templateData);

        return $templateData;
    }

    private function fillSoftDeletes($templateData)
    {
        if (!$this->commandData->getOption('softDelete')) {
            $templateData = str_replace('$SOFT_DELETE_IMPORT$', '', $templateData);
            $templateData = str_replace('$SOFT_DELETE$', '', $templateData);
            $templateData = str_replace('$SOFT_DELETE_DATES$', '', $templateData);
        } else {
            $templateData       = str_replace(
                '$SOFT_DELETE_IMPORT$', "use Illuminate\\Database\\Eloquent\\SoftDeletes;\n",
                $templateData
            );
            $templateData       = str_replace('$SOFT_DELETE$', infy_tab() . "use SoftDeletes;\n", $templateData);
            $deletedAtTimestamp = config('infyom.laravel_generator.timestamps.deleted_at', 'deleted_at');
            $templateData       = str_replace(
                '$SOFT_DELETE_DATES$', infy_nl_tab() . "protected \$dates = ['" . $deletedAtTimestamp . "'];\n",
                $templateData
            );
        }

        return $templateData;
    }

    private function fillDocs($templateData)
    {
        if ($this->commandData->getAddOn('swagger')) {
            $templateData = $this->generateSwagger($templateData);
        } else {
            $docsTemplate = get_template('docs.model', 'laravel-generator');
            $docsTemplate = fill_template($this->commandData->dynamicVars, $docsTemplate);
            $docsTemplate = str_replace('$GENERATE_DATE$', date('F j, Y, g:i a T'), $docsTemplate);

            $templateData = str_replace('$DOCS$', $docsTemplate, $templateData);
        }

        return $templateData;
    }

    public function generateSwagger($templateData)
    {
        $fieldTypes = SwaggerGenerator::generateTypes($this->commandData->fields);

//        $template = get_template('model.model', 'swagger-generator'); // 由于修改了配置'infyom.laravel_generator.path.templates_dir'的model.stub文件,会导致'$DOCS$'替换为重复的文件主体
        $path     = base_path('vendor/infyomlabs/swagger-generator/templates/model/model.stub');
        $template = file_get_contents($path);

        $template = fill_template($this->commandData->dynamicVars, $template);

        $template = str_replace('$REQUIRED_FIELDS$',
            '"' . implode('"' . ', ' . '"', $this->generateRequiredFields()) . '"', $template);

        $propertyTemplate = get_template('model.property', 'swagger-generator');

        $properties = SwaggerGenerator::preparePropertyFields($propertyTemplate, $fieldTypes);

        $template = str_replace('$PROPERTIES$', implode(",\n", $properties), $template);

        $templateData = str_replace('$DOCS$', $template, $templateData);

        return $templateData;
    }

    private function generateRequiredFields()
    {
        $requiredFields = [];

        foreach ($this->commandData->fields as $field) {
            if (!empty($field->validations)) {
                if (str_contains($field->validations, 'required')) {
                    $requiredFields[] = $field->name;
                }
            }
        }

        return $requiredFields;
    }

    private function fillTimestamps($templateData)
    {
        $timestamps = TableFieldsGenerator::getTimestampFieldNames();

        $replace = '';

        if ($this->commandData->getOption('fromTable')) {
            if (empty($timestamps)) {
                $replace = infy_nl_tab() . "public \$timestamps = false;\n";
            } else {
                list($created_at, $updated_at) = collect($timestamps)->map(function ($field) {
                    return !empty($field) ? "'$field'" : 'null';
                });

                $replace .= infy_nl_tab() . "const CREATED_AT = $created_at;";
                $replace .= infy_nl_tab() . "const UPDATED_AT = $updated_at;\n";
            }
        }

        return str_replace('$TIMESTAMPS$', $replace, $templateData);
    }

    private function generateRules()
    {
        $rules = [];

        foreach ($this->commandData->fields as $field) {
            if (!empty($field->validations)) {
                $rule    = "self::FIELD_" . strtoupper($field->name) . " => '" . $field->validations . "'";
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    public function generateCasts()
    {
        $casts = [];

        $timestamps = TableFieldsGenerator::getTimestampFieldNames();

        foreach ($this->commandData->fields as $field) {
            if (in_array($field->name, $timestamps)) {
                continue;
            }

            $rule = 'self::FIELD_' . strtoupper($field->name) . " => ";

            switch ($field->fieldType) {
                case 'integer':
                    $rule .= 'self::CAST_' . strtoupper('int');
                    break;
                case 'double':
                    $rule .= 'self::CAST_' . strtoupper($field->fieldType);
                    break;
                case 'float':
                    $rule .= 'self::CAST_' . strtoupper($field->fieldType);
                    break;
                case 'boolean':
                    $rule .= 'self::CAST_' . strtoupper('bool');
                    break;
                case 'dateTime':
                case 'dateTimeTz':
                    $rule .= 'self::CAST_' . strtoupper('dateTime');
                    break;
                case 'date':
                    $rule .= 'self::CAST_' . strtoupper($field->fieldType);
                    break;
                case 'enum':
                case 'string':
                case 'char':
                case 'text':
                    $rule .= 'self::CAST_' . strtoupper('string');
                    break;
                default:
                    $rule = '';
                    break;
            }

            if (!empty($rule)) {
                $casts[] = $rule;
            }
        }

        return $casts;
    }

    private function generateRelations()
    {
        $relations = [];

        foreach ($this->commandData->relations as $relation) {
            $modelName                 = $relation->inputs[0];
            $this->useRelationModels[] = "use App\\Models\\$modelName;";
            $functionName              = camel_case($modelName);
            $relFunctionName           = strtoupper($functionName);
            $this->relationships[]     = "const REL_$relFunctionName = '$functionName';";
            $relationText              = $relation->getRelationFunctionText();
            if ($relation->type === 'mtm') {
                $joiningTableName      = $relation->inputs[0];
                $this->joiningTables[] = "const JOINING_TABLE_CAT_ROLES = '$joiningTableName';";
                $relationText          = str_replace(';', '->withTimestamps();', $relationText);
            }

            if (!empty($relationText)) {
                $relations[] = $relationText;
            }
        }

        return $relations;
    }

    public function rollback()
    {
        if ($this->rollbackFile($this->path, $this->fileName)) {
            $this->commandData->commandComment('Model file deleted: ' . $this->fileName);
        }
    }

    /**
     * Get the fillable attributes.
     *
     * @return string
     */
    public function getFillable($fillables)
    {
        return implode(',' . infy_nl_tab(1, 2), array_map(function ($v) {
            $v = str_replace("'", '', $v);

            return 'self::FIELD_' . strtoupper($v);
        }, $fillables)) . ",\n";
    }

    private function getFields($fillables)
    {
        return implode(';' . infy_nl_tab(1, 1), array_map(function ($v) {
            $v = str_replace("'", '', $v);

            return 'const FIELD_' . strtoupper($v) . " = '$v'";
        }, $fillables)) . ";\n";
    }

    private function getTransformAliases($fillables)
    {
        return implode(',' . infy_nl_tab(1, 3), array_map(function ($v) {
            $v     = str_replace("'", '', $v);
            $upper = strtoupper($v);

            return "'$v' => \$this->{self::FIELD_$upper}";
            'self::FIELD_' . strtoupper($v);
        }, $fillables)) . ",\n";
    }

    private function getForeignFields($foreignFields)
    {
        return implode(';' . infy_nl_tab(1, 1), array_map(function ($v) {
            $v = str_replace("'", '', $v);

            return 'const FIELD_' . strtoupper($v) . " = '$v'";
        }, $foreignFields)) . ";\n";
    }

    private function getForeignIds($foreignFields)
    {
        return implode(';' . infy_nl_tab(1, 1), array_map(function ($v) {
            $v = str_replace("'", '', $v);

            return 'self::FIELD_' . strtoupper($v) . ",";
        }, $foreignFields)) . "\n";
    }
}
