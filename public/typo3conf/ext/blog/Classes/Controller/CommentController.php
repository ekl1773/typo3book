<?php
declare(strict_types = 1);

/*
 * This file is part of the package t3g/blog.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace T3G\AgencyPack\Blog\Controller;

use T3G\AgencyPack\Blog\Domain\Model\Comment;
use T3G\AgencyPack\Blog\Domain\Model\Post;
use T3G\AgencyPack\Blog\Domain\Repository\PostRepository;
use T3G\AgencyPack\Blog\Notification\CommentAddedNotification;
use T3G\AgencyPack\Blog\Notification\NotificationManager;
use T3G\AgencyPack\Blog\Service\CacheService;
use T3G\AgencyPack\Blog\Service\CommentService;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

class CommentController extends ActionController
{
    protected static $messages = [
        CommentService::STATE_ERROR => [
            'title' => 'message.addComment.error.title',
            'text' => 'message.addComment.error.text',
            'severity' => FlashMessage::ERROR,
        ],
        CommentService::STATE_MODERATION => [
            'title' => 'message.addComment.moderation.title',
            'text' => 'message.addComment.moderation.text',
            'severity' => FlashMessage::INFO,
        ],
        CommentService::STATE_SUCCESS => [
            'title' => 'message.addComment.success.title',
            'text' => 'message.addComment.success.text',
            'severity' => FlashMessage::OK,
        ],
    ];

    /**
     * @var PostRepository
     */
    protected $postRepository;

    /**
     * @var CommentService
     */
    protected $commentService;

    /**
     * @var CacheService
     */
    protected $blogCacheService;

    /**
     * @param PostRepository $postRepository
     */
    public function injectPostRepository(PostRepository $postRepository): void
    {
        $this->postRepository = $postRepository;
    }

    /**
     * @param \T3G\AgencyPack\Blog\Service\CommentService $commentService
     */
    public function injectCommentService(CommentService $commentService): void
    {
        $this->commentService = $commentService;
    }

    /**
     * @param \T3G\AgencyPack\Blog\Service\CacheService $cacheService
     */
    public function injectBlogCacheService(CacheService $cacheService): void
    {
        $this->blogCacheService = $cacheService;
    }

    /**
     * Pre-process request and ensure a valid protocol for submitted URL
     */
    protected function initializeAddCommentAction(): void
    {
        $arguments = $this->request->getArguments();
        if (!empty($arguments['comment']['url'])) {
            $re = '/^(http([s]*):\/\/)(.*)/';
            if (preg_match($re, $arguments['comment']['url'], $matches) === 0) {
                $arguments['comment']['url'] = 'http://' . $arguments['comment']['url'];
            }
            $this->request->setArguments($arguments);
        }
    }

    protected function getErrorFlashMessage(): bool
    {
        return false;
    }

    /**
     * Show comment form.
     *
     * @param Comment|null $comment
     * @throws \TYPO3\CMS\Extbase\Configuration\Exception\InvalidConfigurationTypeException
     * @throws \TYPO3\CMS\Extbase\Persistence\Exception\InvalidQueryException
     */
    public function formAction(Comment $comment = null): void
    {
        $this->view->assign('post', $this->postRepository->findCurrentPost());
        $this->view->assign('comment', $comment);
    }

    /**
     * Add comment to blog post.
     *
     * @param Comment|null $comment
     * @throws \TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException
     * @throws \TYPO3\CMS\Core\Context\Exception\AspectNotFoundException
     * @throws \TYPO3\CMS\Extbase\Configuration\Exception\InvalidConfigurationTypeException
     * @throws \TYPO3\CMS\Extbase\Mvc\Exception\StopActionException
     * @throws \TYPO3\CMS\Extbase\Mvc\Exception\UnsupportedRequestTypeException
     * @throws \TYPO3\CMS\Extbase\Persistence\Exception\IllegalObjectTypeException
     * @throws \TYPO3\CMS\Extbase\Persistence\Exception\InvalidQueryException
     * @throws \TYPO3\CMS\Extbase\Persistence\Exception\UnknownObjectException
     */
    public function addCommentAction(Comment $comment = null): void
    {
        if (!$comment) {
            $this->redirect('form');
        }
        $this->commentService->injectSettings($this->settings['comments']);
        $post = $this->postRepository->findCurrentPost();
        $state = $this->commentService->addComment($post, $comment);
        $this->addFlashMessage(
            LocalizationUtility::translate(self::$messages[$state]['text'], 'blog'),
            LocalizationUtility::translate(self::$messages[$state]['title'], 'blog'),
            self::$messages[$state]['severity']
        );
        if ($state !== CommentService::STATE_ERROR) {
            $comment->setCrdate(new \DateTime());
            GeneralUtility::makeInstance(NotificationManager::class)
                ->notify(GeneralUtility::makeInstance(CommentAddedNotification::class, '', '', [
                    'comment' => $comment,
                    'post' => $post,
                ]));
            $this->blogCacheService->flushCacheByTag('tx_blog_post_' . $post->getUid());
        }
        $this->redirectToUri(
            $this->controllerContext
                ->getUriBuilder()
                ->reset()
                ->setTargetPageUid($post->getUid())
                ->setUseCacheHash(false)
                ->setAddQueryString(true)
                ->setArgumentsToBeExcludedFromQueryString(['tx_blog_commentform', 'cHash'])
                ->buildFrontendUri()
        );
    }

    /**
     * @throws \TYPO3\CMS\Core\Context\Exception\AspectNotFoundException
     * @throws \TYPO3\CMS\Extbase\Configuration\Exception\InvalidConfigurationTypeException
     * @throws \TYPO3\CMS\Extbase\Persistence\Exception\InvalidQueryException
     */
    public function commentsAction(): void
    {
        $post = $this->postRepository->findCurrentPost();
        if ($post instanceof Post) {
            $comments = $this->commentService->getCommentsByPost($post);
            foreach ($comments as $comment) {
                $this->blogCacheService->addTagToPage('tx_blog_comment_' . $comment->getUid());
            }
            $this->view->assign('comments', $comments);
        }
    }
}
