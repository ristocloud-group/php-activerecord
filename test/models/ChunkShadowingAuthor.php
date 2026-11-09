<?php

/**
 * An Author with a static chunk() of its own, in the shape of the chunk() of a consumer's base model (RCModel):
 * PHP calls the real static, and __callStatic() never sees the call, so Author::chunk() forwarding to the builder
 * cannot take over a name that a model class defines. The builder's chunk() is still reached through a chain.
 */
class ChunkShadowingAuthor extends Author
{
    public static $table_name = 'authors';

    public static function chunk(int $size, array $options, callable $each): int
    {
        return -1;
    }
}
