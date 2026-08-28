<?php

declare(strict_types=1);

namespace kernpfad\commerceklaviyo\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\Html;
use kernpfad\commerceklaviyo\CommerceKlaviyo;
use kernpfad\commerceklaviyo\models\KlaviyoListOption;
use kernpfad\commerceklaviyo\services\KlaviyoListsService;
use yii\db\Schema;

/**
 * Single Klaviyo list picker for entries/users — value is a
 * {@see KlaviyoListOption} (or null) with `id` / `name` for Twig forms.
 */
class ListField extends Field
{
    public static function displayName(): string
    {
        return Craft::t('commerce-klaviyo', 'Klaviyo List');
    }

    public static function icon(): string
    {
        return 'envelope';
    }

    public static function dbType(): array|string|null
    {
        return Schema::TYPE_STRING;
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element): mixed
    {
        if ($value instanceof KlaviyoListOption) {
            return $value;
        }

        if (is_array($value)) {
            $value = $value['id'] ?? null;
        }

        if (!is_string($value) || $value === '') {
            return null;
        }

        foreach ($this->availableLists() as $list) {
            if ($list['id'] === $value) {
                return new KlaviyoListOption($list['id'], $list['name'], $list['optInProcess']);
            }
        }

        return new KlaviyoListOption($value, $value);
    }

    public function serializeValue(mixed $value, ?ElementInterface $element): mixed
    {
        if ($value instanceof KlaviyoListOption) {
            return $value->id;
        }

        return is_string($value) ? $value : null;
    }

    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline = false): string
    {
        $selected = $value instanceof KlaviyoListOption ? $value->id : '';
        $options = ['' => Craft::t('commerce-klaviyo', 'Select a list…')];

        foreach ($this->availableLists() as $list) {
            $label = $list['name'];
            if ($list['optInProcess'] !== null && $list['optInProcess'] !== '') {
                $label .= ' (' . $list['optInProcess'] . ')';
            }
            $options[$list['id']] = $label;
        }

        return Craft::$app->getView()->renderTemplate('_includes/forms/select', [
            'id' => Html::id($this->handle ?? 'klaviyo-list'),
            'name' => $this->handle,
            'options' => $options,
            'value' => $selected,
        ]);
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
