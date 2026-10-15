<?php

namespace JothamLec\MarketingToolkit\Http\Controllers\CP;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use JothamLec\MarketingToolkit\Cp\RecordActions;
use JothamLec\MarketingToolkit\NotFound\MissingPath;
use JothamLec\MarketingToolkit\Redirects\RedirectRepository;
use JothamLec\MarketingToolkit\Redirects\Rule;
use Statamic\Actions\Action;
use Statamic\Facades\Site;
use Statamic\Http\Controllers\CP\ActionController as StatamicActionController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs Statamic actions on the redirects and 404 listings. The listing says
 * which it is in the action context.
 */
final class ActionController extends StatamicActionController
{
    /**
     * Runs only the addon's actions, because Statamic's `run()` would run any
     * registered action on our rows and asks only that action to authorize. It
     * checks the class that the handle resolves to, not the handle, because
     * Statamic maps handles to classes and the last registration wins, so another
     * addon's action could take one of ours.
     *
     * @return array<string, mixed>|Response
     */
    public function run(Request $request)
    {
        $action = $request->input('action');
        $class = is_string($action) ? app('statamic.actions')->get($action) : null;

        abort_unless(in_array($class, RecordActions::ACTIONS, true), 403);

        return parent::run($request);
    }

    /**
     * Lists only the addon's actions, as RecordActions explains.
     *
     * @return Collection<int, Action>
     */
    public function bulkActions(Request $request)
    {
        $data = $request->validate([
            'selections' => 'required|array',
            'context' => 'sometimes',
        ]);

        $context = $data['context'] ?? [];

        return RecordActions::for($this->getSelectedItems(collect((array) $data['selections']), $context), $context);
    }

    /**
     * Finds the selected rows among those the listing shows this user, which are
     * 404 rows on the selected site and redirects on sites they may work on. Any
     * other row is treated as if it didn't exist.
     *
     * @param  Collection<int, mixed>  $items
     * @param  array<string, mixed>  $context
     * @return Collection<int, MissingPath>|Collection<int, Rule>
     */
    protected function getSelectedItems($items, $context)
    {
        if (($context['type'] ?? null) === '404s') {
            $selected = MissingPath::query()->shownOn(Site::selected()->handle())->whereIn('id', $items->all())->get();
        } else {
            $repository = app(RedirectRepository::class);
            $selected = $items->unique()
                ->map(fn ($id) => is_scalar($id) ? $repository->find((string) $id) : null)
                ->filter(fn (?Rule $rule) => $rule !== null && $rule->isAccessible())
                ->values();
        }

        abort_if($selected->count() < $items->unique()->count(), 404);

        return $selected;
    }
}
