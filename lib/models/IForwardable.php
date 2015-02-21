<?php

interface IForwardable
{
    public function forward();

    public function canBeForwarded();
}


