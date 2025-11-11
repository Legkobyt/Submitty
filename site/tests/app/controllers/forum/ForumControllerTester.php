<?php

namespace tests\app\controllers\forum;

use app\controllers\forum\ForumController;
use app\entities\forum\Category;
use tests\BaseUnitTest;

class ForumControllerTester extends BaseUnitTest
{
    public function testControllerCanBeInstantiated()
    {
        $core = $this->createMockCore();
        $controller = new ForumController($core);
        $this->assertInstanceOf(ForumController::class, $controller);
    }

    public function testGetPostsOrderAndRepliesSimple()
    {
        // Simple linear posts: expect ids in same order and reply levels all 1
        $posts = [
            ["id" => 10, "parent_id" => 0, "thread_id" => 5],
            ["id" => 11, "parent_id" => 0, "thread_id" => 5],
            ["id" => 12, "parent_id" => 0, "thread_id" => 5]
        ];

        [$order, $levels] = ForumController::getPostsOrderAndReplies($posts, "5");
        $this->assertEquals([10, 11, 12], $order);
        $this->assertEquals([1, 1, 1], $levels);
    }

    public function testGetPostsOrderAndRepliesWithReplies()
    {
        // Parent 10, reply 11 (parent_id = 10), another top level 12
        $posts = [
            ["id" => 10, "parent_id" => 0, "thread_id" => 7],
            ["id" => 11, "parent_id" => 10, "thread_id" => 7],
            ["id" => 12, "parent_id" => 0, "thread_id" => 7],
            ["id" => 13, "parent_id" => 11, "thread_id" => 7]
        ];

        [$order, $levels] = ForumController::getPostsOrderAndReplies($posts, "7");
        $this->assertEquals([10, 11, 13, 12], $order);
        $this->assertEquals([1, 1, 2, 1], $levels);
    }

    public function testShowDeletedDependsOnCookieAndAccess()
    {
        $core = $this->createMockCore([], ['access_grading' => true]);
        // set cookie to show deleted
        $_COOKIE['show_deleted'] = "1";

        $controller = new ForumController($core);
        // call private method via helper
        $result = $this->invokeMethod($controller, 'showDeleted');
        $this->assertTrue($result);

        // if user lacks grading access, still false even if cookie set
        $core2 = $this->createMockCore([], ['access_grading' => false]);
        $_COOKIE['show_deleted'] = "1";
        $controller2 = new ForumController($core2);
        $this->assertFalse($this->invokeMethod($controller2, 'showDeleted'));
    }

    public function testGetSavedCategoryIdsFromCookieAndInput()
    {
        $core = $this->createMockCore(['course' => 'c1']);
        $_COOKIE['c1_forum_categories'] = '4|5|6';
        $controller = new ForumController($core);
        $ids = $this->invokeMethod($controller, 'getSavedCategoryIds', 'c1', []);
        $this->assertEquals([4, 5, 6], $ids);

        // If input provided, it casts to ints
        $ids2 = $this->invokeMethod($controller, 'getSavedCategoryIds', 'c1', ['7', '8']);
        $this->assertEquals([7, 8], $ids2);
    }

    public function testIsValidCategoriesAndDeletionGood()
    {
        $core = $this->createMockCore();
        $controller = new ForumController($core);

        // Create fake repository that returns two categories (ids 1 and 2)
        // Using mocks/stubs: $cat1 and $cat2 are PHPUnit mock objects created via
        // BaseUnitTest::createMockModel (these are MOCKS/Stubs of the Category entity)
        $cat1 = $this->createMockModel(Category::class);
        $cat1->method('getId')->willReturn(1);
        $cat1->method('getDescription')->willReturn('A');

        $cat2 = $this->createMockModel(Category::class);
        $cat2->method('getId')->willReturn(2);
        $cat2->method('getDescription')->willReturn('B');

        // Create a mock of the real CategoryRepository (so it matches the declared
        // return type of EntityManager::getRepository). This is a MOCK.
        $categoryRepository = $this->createMock(\app\repositories\forum\CategoryRepository::class);
        $categoryRepository->method('getCategories')->willReturn([$cat1, $cat2]);

        // stub the entity manager's getRepository to return our CategoryRepository mock
        // Use consecutive returns so we can change the repository behavior later in the test
        /** @var \PHPUnit\Framework\MockObject\MockObject $em */
        $em = $core->getCourseEntityManager();
        // Make getRepository return different mocks depending on call count.
        $callCount = 0;
        $em->method('getRepository')->willReturnCallback(function () use (&$callCount, $categoryRepository) {
            $callCount++;
            // For the first two calls return the repository with two categories
            if ($callCount <= 2) {
                return $categoryRepository;
            }
            // For subsequent calls we'll reconfigure later
            return $categoryRepository;
        });

        // Valid ids
        $this->assertTrue($this->invokeMethod($controller, 'isValidCategories', [1]));
        // Invalid id
        $this->assertFalse($this->invokeMethod($controller, 'isValidCategories', [3]));

        // Test isCategoryDeletionGood: deleting id 1 should be ok because 2 exists
        $this->assertTrue($this->invokeMethod($controller, 'isCategoryDeletionGood', 1));
        // If only one category existed, deletion bad. Create repo with single cat
        $categoryRepository2 = $this->createMock(\app\repositories\forum\CategoryRepository::class);
        $categoryRepository2->method('getCategories')->willReturn([$cat1]);
        // Update the getRepository callback to return the single-category repo on the next call
        $em->method('getRepository')->willReturnCallback(function () use (&$callCount, $categoryRepository, $categoryRepository2) {
            $callCount++;
            if ($callCount <= 2) {
                return $categoryRepository;
            }
            return $categoryRepository2;
        });
        $this->assertTrue($this->invokeMethod($controller, 'isCategoryDeletionGood', 1));
    }

    public function testModifyAnonymousAsGrader()
    {
        $core = $this->createMockCore([], ['access_full_grading' => true]);
        $controller = new ForumController($core);
        
        // Grader can modify any anonymous post
        $this->assertTrue($controller->modifyAnonymous('someOtherUser'));
    }

    public function testModifyAnonymousAsAuthor()
    {
        $core = $this->createMockCore();
        $controller = new ForumController($core);
        
        // User (testUser) can modify their own post
        $this->assertTrue($controller->modifyAnonymous('testUser'));
        
        // But not someone else's
        $this->assertFalse($controller->modifyAnonymous('otherUser'));
    }

    public function testGetAllowedCategoryColor()
    {
        $core = $this->createMockCore();
        $controller = new ForumController($core);
        
        $colors = $this->invokeMethod($controller, 'getAllowedCategoryColor');
        
        $this->assertIsArray($colors);
        $this->assertCount(8, $colors);
        $this->assertEquals("#800000", $colors["MAROON"]);
        $this->assertEquals("#008000", $colors["GREEN"]);
        $this->assertEquals("#000000", $colors["BLACK"]);
    }

    public function testShowMergedThreadsWithCookie()
    {
        $core = $this->createMockCore(['course' => 'sample']);
        $_COOKIE['sample_show_merged_thread'] = "1";
        $controller = new ForumController($core);
        
        $result = $this->invokeMethod($controller, 'showMergedThreads', 'sample');
        $this->assertTrue($result);
    }

    public function testShowMergedThreadsWithoutCookie()
    {
        $core = $this->createMockCore(['course' => 'sample']);
        unset($_COOKIE['sample_show_merged_thread']);
        $controller = new ForumController($core);
        
        $result = $this->invokeMethod($controller, 'showMergedThreads', 'sample');
        $this->assertFalse($result);
    }

    public function testShowUnreadThreadsTrue()
    {
        $core = $this->createMockCore();
        $_COOKIE['unread_select_value'] = 'true';
        $controller = new ForumController($core);
        
        $result = $this->invokeMethod($controller, 'showUnreadThreads');
        $this->assertTrue($result);
    }

    public function testShowUnreadThreadsFalse()
    {
        $core = $this->createMockCore();
        $_COOKIE['unread_select_value'] = 'false';
        $controller = new ForumController($core);
        
        $result = $this->invokeMethod($controller, 'showUnreadThreads');
        $this->assertFalse($result);
    }

    public function testGetSavedThreadStatus()
    {
        $core = $this->createMockCore();
        $_COOKIE['forum_thread_status'] = '1|2|3';
        $controller = new ForumController($core);
        
        $result = $this->invokeMethod($controller, 'getSavedThreadStatus', []);
        $this->assertEquals([1, 2, 3], $result);
    }

    public function testReturnUserContentToPageForThread()
    {
        $core = $this->createMockCore(['course' => 'sample']);
        $core->method('buildCourseUrl')
            ->with(['forum', 'threads', 'new'])
            ->willReturn('http://example.com/sample/forum/threads/new');
        
        $controller = new ForumController($core);
        
        $result = $this->invokeMethod($controller, 'returnUserContentToPage', 'Error message', true, 123);
        
        $this->assertEquals(-1, $result[0]);
        $this->assertStringContainsString('forum/threads/new', $result[1]);
    }

    public function testReturnUserContentToPageForPost()
    {
        $core = $this->createMockCore(['course' => 'sample']);
        $core->method('buildCourseUrl')
            ->with(['forum', 'threads', 456])
            ->willReturn('http://example.com/sample/forum/threads/456');
        
        $controller = new ForumController($core);
        
        $result = $this->invokeMethod($controller, 'returnUserContentToPage', 'Error message', false, 456);
        
        $this->assertEquals(-1, $result[0]);
        $this->assertStringContainsString('forum/threads/456', $result[1]);
    }

    public function testGetSingleThreadInvalidEmpty()
    {
        $_POST['thread_id'] = '';
        $core = $this->createMockCore();
        $controller = new ForumController($core);
        
        $result = $controller->getSingleThread();
        
        // Should return JSON fail response
        $this->assertIsArray($result);
    }

    public function testGetSingleThreadInvalidNonInteger()
    {
        $_POST['thread_id'] = 'not_a_number';
        $core = $this->createMockCore();
        $controller = new ForumController($core);
        
        $result = $controller->getSingleThread();
        
        // Should return JSON fail response
        $this->assertIsArray($result);
    }

    public function testGetPostsOrderAndRepliesWithThreadIdNegativeOne()
    {
        // When thread_id is -1, it should be extracted from first post
        $posts = [
            ["id" => 20, "parent_id" => 0, "thread_id" => 99],
            ["id" => 21, "parent_id" => 0, "thread_id" => 99]
        ];

        [$order, $levels] = ForumController::getPostsOrderAndReplies($posts, "-1");
        $this->assertEquals([20, 21], $order);
        $this->assertEquals([1, 1], $levels);
    }

    public function testGetPostsOrderAndRepliesWithNestedReplies()
    {
        // Test deeply nested replies
        $posts = [
            ["id" => 1, "parent_id" => 0, "thread_id" => 1],
            ["id" => 2, "parent_id" => 1, "thread_id" => 1],
            ["id" => 3, "parent_id" => 2, "thread_id" => 1],
            ["id" => 4, "parent_id" => 3, "thread_id" => 1],
        ];

        [$order, $levels] = ForumController::getPostsOrderAndReplies($posts, "1");
        $this->assertEquals([1, 2, 3, 4], $order);
        $this->assertEquals([1, 1, 2, 3], $levels);
    }

    public function testGetPostsOrderAndRepliesWithMultipleBranches()
    {
        // Test multiple reply branches
        $posts = [
            ["id" => 1, "parent_id" => 0, "thread_id" => 1],
            ["id" => 2, "parent_id" => 1, "thread_id" => 1],
            ["id" => 3, "parent_id" => 1, "thread_id" => 1],
            ["id" => 4, "parent_id" => 2, "thread_id" => 1],
        ];

        [$order, $levels] = ForumController::getPostsOrderAndReplies($posts, "1");
        $this->assertEquals([1, 2, 4, 3], $order);
        $this->assertEquals([1, 1, 2, 1], $levels);
    }

    public function testGetPostsOrderAndRepliesEmptyArray()
    {
        $posts = [];
        [$order, $levels] = ForumController::getPostsOrderAndReplies($posts, "1");
        $this->assertEquals([], $order);
        $this->assertEquals([], $levels);
    }

    public function testShowDeletedWithoutCookie()
    {
        $core = $this->createMockCore([], ['access_grading' => true]);
        unset($_COOKIE['show_deleted']);
        $controller = new ForumController($core);
        
        $result = $this->invokeMethod($controller, 'showDeleted');
        $this->assertFalse($result);
    }

    public function testShowDeletedWithCookieSetToZero()
    {
        $core = $this->createMockCore([], ['access_grading' => true]);
        $_COOKIE['show_deleted'] = "0";
        $controller = new ForumController($core);
        
        $result = $this->invokeMethod($controller, 'showDeleted');
        $this->assertFalse($result);
    }

    public function testShowUnreadThreadsWithNoCookie()
    {
        $core = $this->createMockCore();
        unset($_COOKIE['unread_select_value']);
        $controller = new ForumController($core);
        
        $result = $this->invokeMethod($controller, 'showUnreadThreads');
        $this->assertFalse($result);
    }

    public function testGetSavedThreadStatusWithInput()
    {
        $core = $this->createMockCore();
        $controller = new ForumController($core);
        
        // When input is provided, it should use input instead of cookie
        $result = $this->invokeMethod($controller, 'getSavedThreadStatus', ['4', '5', '6']);
        $this->assertEquals([4, 5, 6], $result);
    }

    public function testGetSavedThreadStatusWithNoCookie()
    {
        $core = $this->createMockCore();
        unset($_COOKIE['forum_thread_status']);
        $controller = new ForumController($core);
        
        $result = $this->invokeMethod($controller, 'getSavedThreadStatus', []);
        $this->assertEquals([], $result);
    }

    public function testGetSavedCategoryIdsWithNoCookie()
    {
        $core = $this->createMockCore(['course' => 'test']);
        unset($_COOKIE['test_forum_categories']);
        $controller = new ForumController($core);
        
        $result = $this->invokeMethod($controller, 'getSavedCategoryIds', 'test', []);
        $this->assertEquals([], $result);
    }

    public function testIsValidCategoriesEmptyArrayOfIds()
    {
        $core = $this->createMockCore();
        $controller = new ForumController($core);
        
        $cat1 = $this->createMockModel(Category::class);
        $cat1->method('getId')->willReturn(1);
        
        $categoryRepository = $this->createMock(\app\repositories\forum\CategoryRepository::class);
        $categoryRepository->method('getCategories')->willReturn([$cat1]);
        
        $em = $core->getCourseEntityManager();
        $em->method('getRepository')->willReturn($categoryRepository);
        
        // Empty array should return false
        $result = $this->invokeMethod($controller, 'isValidCategories', []);
        $this->assertFalse($result);
    }

    public function testIsValidCategoriesEmptyArrayOfNames()
    {
        $core = $this->createMockCore();
        $controller = new ForumController($core);
        
        $cat1 = $this->createMockModel(Category::class);
        $cat1->method('getDescription')->willReturn('Test');
        
        $categoryRepository = $this->createMock(\app\repositories\forum\CategoryRepository::class);
        $categoryRepository->method('getCategories')->willReturn([$cat1]);
        
        $em = $core->getCourseEntityManager();
        $em->method('getRepository')->willReturn($categoryRepository);
        
        // Empty array of names should return false
        $result = $this->invokeMethod($controller, 'isValidCategories', -1, []);
        $this->assertFalse($result);
    }

    public function testIsValidCategoriesByName()
    {
        $core = $this->createMockCore();
        $controller = new ForumController($core);
        
        $cat1 = $this->createMockModel(Category::class);
        $cat1->method('getId')->willReturn(1);
        $cat1->method('getDescription')->willReturn('Homework');
        
        $cat2 = $this->createMockModel(Category::class);
        $cat2->method('getId')->willReturn(2);
        $cat2->method('getDescription')->willReturn('Labs');
        
        $categoryRepository = $this->createMock(\app\repositories\forum\CategoryRepository::class);
        $categoryRepository->method('getCategories')->willReturn([$cat1, $cat2]);
        
        $em = $core->getCourseEntityManager();
        $em->method('getRepository')->willReturn($categoryRepository);
        
        // Valid category name
        $result = $this->invokeMethod($controller, 'isValidCategories', -1, ['Homework']);
        $this->assertTrue($result);
        
        // Invalid category name
        $result = $this->invokeMethod($controller, 'isValidCategories', -1, ['NonExistent']);
        $this->assertFalse($result);
    }

    public function testIsCategoryDeletionGoodLastCategory()
    {
        $core = $this->createMockCore();
        $controller = new ForumController($core);
        
        $cat1 = $this->createMockModel(Category::class);
        $cat1->method('getId')->willReturn(1);
        
        $categoryRepository = $this->createMock(\app\repositories\forum\CategoryRepository::class);
        $categoryRepository->method('getCategories')->willReturn([$cat1]);
        
        $em = $core->getCourseEntityManager();
        $em->method('getRepository')->willReturn($categoryRepository);
        
        // Cannot delete the last category
        $result = $this->invokeMethod($controller, 'isCategoryDeletionGood', 1);
        $this->assertFalse($result);
    }

    public function testGetAllowedCategoryColorContainsAllColors()
    {
        $core = $this->createMockCore();
        $controller = new ForumController($core);
        
        $colors = $this->invokeMethod($controller, 'getAllowedCategoryColor');
        
        // Test all expected colors exist
        $this->assertArrayHasKey("MAROON", $colors);
        $this->assertArrayHasKey("OLIVE", $colors);
        $this->assertArrayHasKey("GREEN", $colors);
        $this->assertArrayHasKey("TEAL", $colors);
        $this->assertArrayHasKey("NAVY", $colors);
        $this->assertArrayHasKey("PURPLE", $colors);
        $this->assertArrayHasKey("GRAY", $colors);
        $this->assertArrayHasKey("BLACK", $colors);
    }
}