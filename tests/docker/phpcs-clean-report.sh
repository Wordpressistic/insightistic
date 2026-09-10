#!/bin/sh
vendor/bin/phpcs --standard=phpcs.xml.dist --report=source --ignore='*/vendor/*,*/node_modules/*,*/build/*,*/dist/*,*/tests/*,*/scripts/*' .
