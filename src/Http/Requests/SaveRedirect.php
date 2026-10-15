<?php

namespace JothamLec\MarketingToolkit\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use JothamLec\MarketingToolkit\Redirects\Campaign;
use JothamLec\MarketingToolkit\Redirects\Rule;
use JothamLec\MarketingToolkit\Support\Sites;
use Statamic\Facades\Blueprint;
use Statamic\Fields\Blueprint as BlueprintObject;

/**
 * This request handles the redirect form (Marketing → Redirects, create and edit). The form is
 * Statamic's publish form on blueprint(). Its values are processed by that blueprint and then checked
 * with the same rules that a CSV row passes. The route checks the permission.
 */
final class SaveRedirect extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Converts the form's values into the form a redirect stores. A campaign's UTM tags become the target's
     * query string, and choosing no site (or having a single site) means every site.
     */
    protected function prepareForValidation(): void
    {
        $values = self::blueprint()->fields()->addValues($this->all())->process()->values();
        $target = $values->get('target');
        $site = $values->get('site');

        $this->merge([
            'source' => $values->get('source'),
            'target' => is_string($target) && $target !== '' ? Campaign::withTags($target, $values->only(Campaign::TAGS)->all()) : $target,
            'status' => (int) ($values->get('status') ?? 301),
            'active' => (bool) ($values->get('active') ?? false),
            'site' => is_string($site) && $site !== '' && Sites::multiple() ? $site : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $redirect = $this->route('redirect');

        return Rule::rules(
            (string) $this->input('source'),
            $this->input('site'),
            ignoreId: is_string($redirect) ? $redirect : null,
            sites: Sites::accessible(),
        );
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return Rule::messages();
    }

    public static function blueprint(): BlueprintObject
    {
        // This section holds a campaign link's UTM tags, which are added to the target when it is saved.
        $campaign = [[
            'display' => __('marketing-toolkit::cp.redirect_form.campaign'),
            'collapsible' => true,
            'collapsed' => true,
            'fields' => array_map(fn (string $tag) => ['handle' => $tag, 'field' => [
                'type' => 'text', 'display' => $tag, 'width' => $tag === 'utm_campaign' ? 100 : 50,
            ]], Campaign::TAGS),
        ]];

        return Blueprint::make('mt_redirect')->setContents(['tabs' => ['main' => ['sections' => [['fields' => [
            ['handle' => 'source', 'field' => [
                'type' => 'text', 'display' => __('marketing-toolkit::cp.redirect_form.source'),
            ]],
            ['handle' => 'target', 'field' => [
                'type' => 'text', 'display' => __('marketing-toolkit::cp.redirect_form.target'),
            ]],
            ['handle' => 'status', 'field' => [
                'type' => 'button_group', 'display' => __('marketing-toolkit::cp.redirect_form.status'), 'width' => 66, 'default' => '301',
                'options' => [
                    '301' => __('marketing-toolkit::cp.redirect_form.status_301'),
                    '302' => __('marketing-toolkit::cp.redirect_form.status_302'),
                    '410' => __('marketing-toolkit::cp.redirect_form.status_410'),
                ],
            ]],
            ['handle' => 'active', 'field' => ['type' => 'toggle', 'display' => __('marketing-toolkit::cp.redirect_form.active'), 'width' => 33, 'default' => true]],
            ...(Sites::multiple() ? [['handle' => 'site', 'field' => [
                // Only someone who may work on every site can choose every site (by choosing none).
                'type' => 'select', 'display' => __('marketing-toolkit::cp.redirect_form.site'), 'options' => array_intersect_key(Sites::options(), array_flip(Sites::accessible())), 'clearable' => Sites::accessesAll(),
                'placeholder' => Sites::accessesAll() ? __('marketing-toolkit::cp.redirect_form.all_sites') : null,
            ]]] : []),
        ]], ...$campaign]]]]);
    }
}
