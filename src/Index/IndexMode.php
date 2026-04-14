<?php

declare(strict_types=1);

namespace Greph\Index;

enum IndexMode: string
{
    case Text = 'text';
    case AstIndex = 'ast-index';
    case AstCache = 'ast-cache';

    public function label(): string
    {
        return $this->value;
    }
}
