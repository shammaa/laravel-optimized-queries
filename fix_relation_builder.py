
import os

file_path = r'e:\laravel\packages\laravel-optimized-queries\src\Services\RelationBuilder.php'
with open(file_path, 'r', encoding='utf-8') as f:
    content = f.read()

# Fix indentation and stray braces in the sections we modified
content = content.replace(
    "        if ($callback) {\n            $callback($subQuery);\n        }\n            $wheres = $subQuery->getQuery()->wheres ?? [];\n            if (!empty($wheres)) {\n                $whereClause = ' AND ' . $this->buildWhereClause($wheres, $relatedTable, $subQuery->getBindings());\n            }\n        }",
    "        if ($callback) {\n            $callback($subQuery);\n        }\n        $wheres = $subQuery->getQuery()->wheres ?? [];\n        if (!empty($wheres)) {\n            $whereClause = ' AND ' . $this->buildWhereClause($wheres, $relatedTable, $subQuery->getBindings());\n        }"
)

with open(file_path, 'w', encoding='utf-8') as f:
    f.write(content)
