<?php

// Table name is inferred as "flights". The (departure, destination) UNIQUE
// index in upsert.sql is the conflict target used by Model::upsert().
class Flight extends ActiveRecord\Model {}
