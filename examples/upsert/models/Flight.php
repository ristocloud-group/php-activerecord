<?php

/**
 * @property int                     $id
 * @property string                  $departure
 * @property string                  $destination
 * @property int                     $price
 * @property \ActiveRecord\DateTime  $created_at
 * @property \ActiveRecord\DateTime  $updated_at
 *
 * @method static Flight|null find_by_departure(string $departure)
 */
// Table name is inferred as "flights". The (departure, destination) UNIQUE
// index in upsert.sql is the conflict target used by Model::upsert().
class Flight extends ActiveRecord\Model {}
