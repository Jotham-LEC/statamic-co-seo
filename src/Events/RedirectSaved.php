<?php

namespace JothamLec\MarketingToolkit\Events;

use JothamLec\MarketingToolkit\Redirects\Rule;
use Statamic\Contracts\Git\ProvidesCommitMessage;
use Statamic\Events\Event;

/**
 * A redirect rule was saved, through the control panel, an import, an automatic redirect or code that uses
 * the RedirectRepository, whichever driver stores it. With Statamic's Git automation on, it commits the
 * rule files (the addon registers it with Git::listen()). $authenticatedUser is the user who did it.
 */
class RedirectSaved extends Event implements ProvidesCommitMessage
{
    public function __construct(public Rule $redirect) {}

    public function commitMessage()
    {
        return __('marketing-toolkit::cp.redirects.commit_saved', [], config('statamic.git.locale'));
    }
}
