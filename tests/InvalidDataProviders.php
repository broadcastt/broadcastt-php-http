<?php

namespace Tests;

trait InvalidDataProviders
{

    public function invalidChannelProvider()
    {
        return [
            'Trailing Colon' => [
                'test-channel:'
            ],
            'Leading Colon' => [
                ':test-channel'
            ],
            'Trailing Colon And New Line' => [
                "test-channel\n:"
            ],
            'Leading Colon And New Line' => [
                ":\ntest-channel"
            ],
        ];
    }

    public function invalidChannelsProvider()
    {
        return [
            'Array With Invalid Channel Name' => [
                ['test-channel', 'test-channel:']
            ],
        ];
    }

    public function invalidSocketIdProvider()
    {
        return [
            'Missing Fraction' => ['1.'],
            'Missing Whole' => ['.1'],
            'Trailing Colon' => ['1.1:'],
            'Leading Colon' => [':1.1'],
            'Trailing Colon And New Line' => ["1.1\n:"],
            'Leading Colon And New Line' => [":\n1.1"],
            'False' => [false],
            'Empty String' => [''],
        ];
    }

}