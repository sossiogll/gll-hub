<?php

declare(strict_types=1);

namespace Gll\Hub\Contracts\Content;

/** The kinds of field a content type can have. The core validates and stores them; the admin draws them. */
enum FieldType: string
{
    case String = 'string';
    case Text = 'text';
    case Markdown = 'markdown';
    case Url = 'url';
    case Color = 'color';
    case Integer = 'integer';
    case Boolean = 'boolean';
    case Date = 'date';
    case Enum = 'enum';
    /** A list of small objects, each shaped by Field::$of (features, decisions…). */
    case List = 'list';
    /** One object made of named fields, each shaped by Field::$of (a graph of two lists, an address…). */
    case Group = 'group';
    /** A link to other entries, by content type key (a sequel, related books…). */
    case Reference = 'reference';
    /** One or more images or files from the media library. */
    case Media = 'media';
}
