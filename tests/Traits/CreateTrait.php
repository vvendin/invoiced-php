<?php

namespace Invoiced\Tests\Traits;

use GuzzleHttp\Psr7\Response;

trait CreateTrait
{
    /**
     * @return void
     */
    public function testCreate()
    {
        $client = $this->makeClient(new Response(201, [], '{"id":456}'));

        $class = static::OBJECT_CLASS;
        $newObj = (new $class($client))->create(['name' => 'Nancy']);

        $this->assertInstanceOf($class, $newObj);
        $this->assertEquals(456, $newObj->id);
    }
}
