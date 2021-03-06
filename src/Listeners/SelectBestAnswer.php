<?php

/*
 * This file is part of fof/best-answer.
 *
 * Copyright (c) 2019 FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\BestAnswer\Listeners;

use Carbon\Carbon;
use Flarum\Discussion\Event\Saving;
use Flarum\Notification\Notification;
use Flarum\Notification\NotificationSyncer;
use Flarum\Post\Post;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use FoF\BestAnswer\Helpers;
use FoF\BestAnswer\Notification\AwardedBestAnswerBlueprint;
use FoF\BestAnswer\Notification\SelectBestAnswerBlueprint;
use Illuminate\Support\Arr;
use Symfony\Component\Translation\TranslatorInterface;

class SelectBestAnswer
{
    private $key = 'attributes.bestAnswerPostId';

    /**
     * @var NotificationSyncer
     */
    private $notifications;

    /**
     * @var TranslatorInterface
     */
    protected $translator;

    public function __construct(NotificationSyncer $notifications, TranslatorInterface $translator)
    {
        $this->notifications = $notifications;
        $this->translator = $translator;
    }

    public function handle(Saving $event)
    {
        if (!Arr::has($event->data, $this->key)) {
            return;
        }

        $discussion = $event->discussion;
        $id = (int) Arr::get($event->data, $this->key);

        if (!isset($id) || !$discussion->exists || $discussion->best_answer_post_id == $id) {
            return;
        }

        $post = $event->discussion->posts()->find($id);

        if ($post && !Helpers::canSelectPostAsBestAnswer($event->actor, $post)) {
            throw new PermissionDeniedException();
        }

        if ($id > 0) {
            $discussion->best_answer_post_id = $id;
            $discussion->best_answer_user_id = $event->actor->id;
            $discussion->best_answer_set_at = Carbon::now();

            Notification::where('type', 'selectBestAnswer')->where('subject_id', $discussion->id)->delete();
            $this->notifyUserOfBestAnswerSet($event);
        } elseif ($id == 0) {
            $discussion->best_answer_post_id = null;
            $discussion->best_answer_user_id = null;
            $discussion->best_answer_set_at = null;
        }

        $this->notifications->delete(new SelectBestAnswerBlueprint($discussion, $this->translator));
    }

    public function notifyUserOfBestAnswerSet(Saving $event): void
    {
        $actor = $event->actor;
        $bestAnswerAuthoredBy = $this->getUserFromPost($event->discussion->best_answer_post_id);

        $this->notifications->sync(new AwardedBestAnswerBlueprint($event->discussion, $actor, $this->translator), [$bestAnswerAuthoredBy]);
    }

    public function getUserFromPost(int $post_id): User
    {
        return Post::find($post_id)->user;
    }
}
