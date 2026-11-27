<?php

/**
 * @file plugins/generic/reviewerDirectory/classes/BatchHydrator.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class BatchHydrator
 *
 * @brief Makes core objects (publications, authors, affiliations, review assignments,
 *  users) from their rows the way PKP\core\EntityDAO::fromRow() does, with their
 *  properties typed by the schema, but with one query of settings for all of them
 *  instead of one per object.
 *
 *  It leaves out what each DAO adds after that on its own (a publication's keywords,
 *  citations and authors, a user's roles...), which the report does not show and which
 *  costs several queries per object: on a journal with thousands of submissions, an
 *  export loading full objects made more than 14,000 queries for 360 submissions.
 */

namespace APP\plugins\generic\reviewerDirectory\classes;

use Illuminate\Support\Facades\DB;
use PKP\core\DataObject;
use PKP\core\EntityDAO;
use PKP\db\DAO;

class BatchHydrator
{
    protected static ?DAO $converter = null;

    /**
     * Objects of an entity from rows of its primary table, keyed by id.
     *
     * @param iterable<object> $rows
     *
     * @return array<int,DataObject>
     */
    public static function hydrate(EntityDAO $dao, iterable $rows): array
    {
        $schema = app()->get('schema')->get($dao->schema);
        $converter = self::$converter ??= new DAO();
        $objects = [];
        foreach ($rows as $row) {
            $object = $dao->newDataObject();
            foreach ($dao->primaryTableColumns as $property => $column) {
                if (property_exists($row, $column)) {
                    $object->setData($property, $converter->convertFromDB($row->{$column}, $schema->properties->{$property}->type ?? 'string', true));
                }
            }
            $objects[(int) $row->{$dao->primaryKeyColumn}] = $object;
        }

        if ($dao->settingsTable && $objects) {
            foreach (array_chunk(array_keys($objects), 500) as $ids) {
                $settings = DB::table($dao->settingsTable)->whereIn($dao->primaryKeyColumn, $ids)->get();
                foreach ($settings as $setting) {
                    if (!empty($schema->properties->{$setting->setting_name})) {
                        $objects[(int) $setting->{$dao->primaryKeyColumn}]->setData(
                            $setting->setting_name,
                            $converter->convertFromDB($setting->setting_value, $schema->properties->{$setting->setting_name}->type),
                            empty($setting->locale) ? null : $setting->locale
                        );
                    }
                }
            }
        }

        return $objects;
    }

    /**
     * Objects of an entity whose rows have one of some values in a column.
     *
     * @param int[] $values
     *
     * @return array<int,DataObject>
     */
    public static function where(EntityDAO $dao, string $column, array $values, ?string $orderBy = null): array
    {
        if (!$values) {
            return [];
        }
        $rows = [];
        foreach (array_chunk(array_values(array_unique($values)), 500) as $chunk) {
            $q = DB::table($dao->table)->whereIn($column, $chunk);
            if ($orderBy) {
                $q->orderBy($orderBy);
            }
            $q->orderBy($dao->primaryKeyColumn);
            array_push($rows, ...$q->get()->all());
        }
        return self::hydrate($dao, $rows);
    }
}
