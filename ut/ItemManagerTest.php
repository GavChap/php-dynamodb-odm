<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2016-09-08
 * Time: 20:56
 */

namespace Oasis\Mlib\ODM\Dynamodb\Ut;

use Oasis\Mlib\ODM\Dynamodb\ItemManager;

class ItemManagerTest extends \PHPUnit_Framework_TestCase
{
    /** @var  ItemManager */
    protected $itemManager;
    
    protected function setUp()
    {
        parent::setUp();
        $this->itemManager = new ItemManager(
            UTConfig::$dynamodbConfig, UTConfig::$tablePrefix, __DIR__ . "/cache", true
        );
    }
    
    public function testPersistAndGet()
    {
        $id   = mt_rand(1000, PHP_INT_MAX);
        $user = new User();
        $user->setId($id);
        $user->setName('Alice');
        $this->itemManager->persist($user);
        $this->itemManager->flush();
        
        /** @var User $user2 */
        $user2 = $this->itemManager->get(User::class, ['id' => $id]);
        
        self::assertEquals($user, $user2); // user object will be reused when same primary keys are used
        self::assertEquals('Alice', $user2->getName());
        
        return $id;
    }
    
    /**
     * @depends testPersistAndGet
     *
     * @param $id
     */
    public function testEdit($id)
    {
        /** @var User $user */
        $user = $this->itemManager->get(User::class, ['id' => $id]);
        self::assertInstanceOf(User::class, $user);
        self::assertNotEquals('John', $user->getName());
        $user->setName('John');
        $this->itemManager->flush();
        
        $this->itemManager->clear();
        /** @var User $user2 */
        $user2 = $this->itemManager->get(User::class, ['id' => $id]);
        
        self::assertInstanceOf(User::class, $user2);
        self::assertNotEquals($user, $user2);
        self::assertEquals('John', $user2->getName());
        
        return $id;
    }
    
    /**
     * @depends testEdit
     *
     * @param $id
     */
    public function testCASTimestamp($id)
    {
        /** @var User $user */
        $user = $this->itemManager->get(User::class, ['id' => $id]);
        sleep(1);
        $user->setWage(777);
        $time = time();
        $this->itemManager->flush();
        
        $user        = $this->itemManager->get(User::class, ['id' => $id]);
        $lastUpdated = $user->getLastUpdated();
        self::assertLessThanOrEqual(1, abs($lastUpdated - $time));
        
        return $id;
    }
    
    /**
     * @depends testCASTimestamp
     *
     * @param $id
     */
    public function testDelete($id)
    {
        /** @var User $user */
        $user = $this->itemManager->get(User::class, ['id' => $id]);
        self::assertInstanceOf(User::class, $user);
        
        $this->itemManager->remove($user);
        $this->itemManager->flush();
        
        $this->itemManager->clear();
        /** @var User $user */
        $user = $this->itemManager->get(User::class, ['id' => $id]);
        self::assertNull($user);
    }
    
    public function testQueryAndScan()
    {
        $base = mt_rand(100, PHP_INT_MAX);
        
        $users = [];
        for ($i = 0; $i < 10; ++$i) {
            $id   = $base + $i;
            $user = new User();
            $user->setId($id);
            $user->setName('Batch #' . ($i + 1));
            $user->setHometown(((($i % 2) == 0) ? 'LA' : 'NY') . $base);
            $user->setAge(46 + $i); // 46 to 55
            $user->setWage(12345);
            $users[] = $user;
            $this->itemManager->persist($user);
        }
        
        $this->itemManager->flush();
        $this->itemManager->clear();
        
        $result = $this->itemManager->getRepository(User::class)->query(
            '#hometown = :hometown AND #age > :age',
            ['#hometown' => 'hometown', '#age' => 'age'],
            [':hometown' => 'NY' . $base, ':age' => 45],
            'hometown-age-index'
        );
        self::assertEquals(5, count($result));
        
        $count = 0;
        $this->itemManager->getRepository(User::class)->scanAndRun(
            function (User $user) use (&$count) {
                $count++;
                $this->itemManager->remove($user);
            },
            '#wage = :wage AND #id BETWEEN :idmin AND :idmax ',
            [
                '#wage' => 'salary',
                '#id'   => 'id',
            ],
            [
                ':wage'  => 12345,
                ':idmin' => $base,
                ':idmax' => $base + 10,
            ]
        );
        self::assertEquals(10, $count);
        
        $this->itemManager->flush();
    }
}