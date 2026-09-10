#!/bin/sh
vendor/bin/phpcs . --sniffs=Squiz.PHP.DisallowSizeFunctionsInLoops,Universal.NamingConventions.NoReservedKeywordParameterNames,Generic.CodeAnalysis.UnusedFunctionParameter,WordPress.DateTime.CurrentTimeTimestamp,Universal.Operators.StrictComparisons --standard=WordPress
