<?php

declare(strict_types=1);

namespace kernpfad\commerceklaviyo\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\Html;
use craft\helpers\Json;
use kernpfad\commerceklaviyo\CommerceKlaviyo;
use kernpfad\commerceklaviyo\models\KlaviyoListOption;
use kernpfad\commerceklaviyo\services\KlaviyoListsService;
use yii\db\Schema;

/**
 * Multi Klaviyo list picker — value is a list of {@see KlaviyoListOption}.
 */
class ListsField extends Field
{
    public static function displayName(): string
    {
        return Craft::t('commerce-klaviyo', 'Klaviyo Lists');
    }

    public static function icon(): string
    {
        return 'envelopes';
    }

    public static function dbType(): array|string|null
    {
        return Schema::TYPE_TEXT;
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element): mixed
    {
        $ids = $this->extractIds($value);
        if ($ids === []) {
            return [];
        }

        $byId = [];
        foreach ($this->availableLists() as $list) {
            $byId[$list['id']] = new KlaviyoListOption($list['id'], $list['name'], $list['optInProcess']);
        }

        $result = [];
        foreach ($ids as $id) {
            $result[] = $byId[$id] ?? new KlaviyoListOption($id, $id);
        }

        return $result;
    }

    public function serializeValue(mixed $value, ?ElementInterface $element): mixed
    {
        $ids = [];
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($item instanceof KlaviyoListOption) {
                    $ids[] = $item->id;
                } elseif (is_string($item) && $item !== '') {
                    $ids[] = $item;
                }
            }
        }

        return Json::encode($ids);
    }

    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline = false): string
    {
        $selected = [];
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($item instanceof KlaviyoListOption) {
                    $selected[] = $item->id;
                }
            }
        }

        $options = [];
        foreach ($this->availableLists() as $list) {
            $label = $list['name'];
            if ($list['optInProcess'] !== null && $list['optInProcess'] !== '') {
                $label .= ' (' . $list['optInProcess'] . ')';
            }
            $options[] = [
                'label' => $label,
                'value' => $list['id'],
            ];
        }

        return Craft::$app->getView()->renderTemplate('_includes/forms/checkboxGroup', [
            'id' => Html::id($this->handle ?? 'klaviyo-lists'),
            'name' => $this->handle,
            'options' => $options,
            'values' => $selected,
        ]);
    }

    /**
     * @return list<string>
     */
    private function extractIds(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            $decoded = Json::decodeIfJson($value);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($value)) {
            return [];
        }

        if ($value !== [] && is_array(reset($value))) {
            $ids = [];
            foreach ($value as $row) {
                if (is_array($row) && isset($row['id']) && is_string($row['id'])) {
                    $ids[] = $row['id'];
                }
            }

            return $ids;
        }

        $ids = [];
        foreach ($value as $item) {
            if ($item instanceof KlaviyoListOption) {
                $ids[] = $item->id;
            } elseif (is_string($item) && $item !== '') {
                $ids[] = $item;
            }
        }

        return $ids;
    }

    /**
     * @return list<array{id: string, name: string, optInProcess: string|null}>
     */
    private function availableLists(): array
    {
        if (!class_exists(\Yii::class, false) || \Yii::$app === null) {
            return [];
        }

        $plugin = CommerceKlaviyo::getInstance();
        if ($plugin === null) {
            return [];
        }

        try {
            return (new KlaviyoListsService($plugin->getKlaviyoClient()))->getLists();
        } catch (\Throwable) {
            return [];
        }
    }
}
