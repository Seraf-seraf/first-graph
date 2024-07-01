<?php

require_once 'vendor/autoload.php';

use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Schema;
use GraphQL\GraphQL;

// Параметры подключения к базе данных MySQL
$host = 'localhost';
$dbname = 'todo-rest';
$username = 'root';
$password = '';

// Установка соединения с базой данных через PDO
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException | Error $e) {
    file_put_contents('error.log', "Connection failed: " . $e->getMessage() . "\n", FILE_APPEND);
    die("Connection failed: " . $e->getMessage());
}

function createTask($title, $description, $due_date, $status): array
{
    global $pdo;

    try {
        $status = $status ? 1 : 0;
        $dateTime = DateTime::createFromFormat('Y-m-d H:i:s', $due_date)->format('Y-m-d H:i:s');
        $query = "INSERT INTO tasks (title, description, due_date, status) VALUES (:title, :description, :due_date, :status)";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':title', $title);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':due_date', $dateTime);
        $stmt->bindParam(':status', $status);
        $stmt->execute();
        $insertId = $pdo->lastInsertId();

        return [
            'id' => $insertId,
            'title' => $title,
            'description' => $description,
            'due_date' => $due_date,
            'status' => $status
        ];
    } catch (PDOException | Error $e) {
        file_put_contents('error.log', $e->getMessage() . "\n", FILE_APPEND);
        return [
            'error' => [
                'message' => $e->getMessage()
            ]
        ];
    }
}

function updateTask($id, $title = null, $description = null, $due_date = null, $status = null)
{
    global $pdo;

    $fields = [];
    $params = [':id' => $id];

    if ($title !== null) {
        $fields[] = "title = :title";
        $params[':title'] = $title;
    }
    if ($description !== null) {
        $fields[] = "description = :description";
        $params[':description'] = $description;
    }
    if ($due_date !== null) {
        $fields[] = "due_date = :due_date";
        $params[':due_date'] = DateTime::createFromFormat('Y-m-d H:i:s', $due_date)->format('Y-m-d H:i:s');
    }
    if ($status !== null) {
        $fields[] = "status = :status";
        $params[':status'] = $status ? 1 : 0;
    }

    if (empty($fields)) {
        throw new Exception("No fields to update");
    }

    $query = "UPDATE tasks SET " . implode(', ', $fields) . " WHERE id = :id";
    $stmt = $pdo->prepare($query);

    foreach ($params as $param => $value) {
        $stmt->bindValue($param, $value);
    }

    $stmt->execute();

    return getTaskById($id);
}

function deleteTask($id): array
{
    global $pdo;

    $query = "DELETE FROM tasks WHERE id = :id";
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':id', $id);
    $stmt->execute();

    return ['id' => $id];
}

// Функция для получения задачи по ID
function getTaskById($id) {
    global $pdo;

    $query = "SELECT * FROM tasks WHERE id = :id";
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':id', $id);
    $stmt->execute();
    $task = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        throw new Exception("Task not found");
    }

    return $task;
}

// Определение типа Task
$taskType = new ObjectType([
    'name' => 'Task',
    'fields' => [
        'id' => ['type' => Type::id()],
        'title' => ['type' => Type::nonNull(Type::string())],
        'description' => ['type' => Type::string()],
        'due_date' => ['type' => Type::nonNull(Type::string())],
        'status' => ['type' => Type::boolean()],
    ],
]);

$queryType = new ObjectType([
    'name' => 'Query',
    'fields' => [
        'task' => [
            'type' => $taskType,
            'args' => [
                'id' => ['type' => Type::nonNull(Type::id())],
            ],
            'resolve' => function ($root, $args) {
                return getTaskById($args['id']);
            },
        ],
    ],
]);

// Определение мутаций GraphQL
$mutationType = new ObjectType([
    'name' => 'Mutation',
    'fields' => [
        'createTask' => [
            'type' => $taskType,
            'args' => [
                'title' => ['type' => Type::nonNull(Type::string())],
                'description' => ['type' => Type::string()],
                'due_date' => ['type' => Type::nonNull(Type::string())],
                'status' => ['type' => Type::boolean()],
            ],
            'resolve' => function ($root, $args) {
                return createTask($args['title'], $args['description'] ?? null, $args['due_date'] ?? null, $args['status'] ?? false);
            },
        ],
        'updateTask' => [
            'type' => $taskType,
            'args' => [
                'id' => ['type' => Type::nonNull(Type::id())],
                'title' => ['type' => Type::string()],
                'description' => ['type' => Type::string()],
                'due_date' => ['type' => Type::string()],
                'status' => ['type' => Type::boolean()],
            ],
            'resolve' => function ($root, $args) {
                $title = $args['title'] ?? null;
                $description = $args['description'] ?? null;
                $due_date = $args['due_date'] ?? null;
                $status = $args['status'] ?? null;

                return updateTask($args['id'], $title, $description, $due_date, $status);
            },
        ],
        'deleteTask' => [
            'type' => $taskType,
            'args' => [
                'id' => ['type' => Type::nonNull(Type::id())],
            ],
            'resolve' => function ($root, $args) {
                return deleteTask($args['id']);
            },
        ],
    ],
]);

$schema = new Schema([
    'query' => $queryType,
    'mutation' => $mutationType,
]);

$input = file_get_contents('php://input');
$query = json_decode($input, true);

try {
    $result = GraphQL::executeQuery($schema, $query['query']);
    $output = $result->toArray();
} catch (Exception | Error $e) {
    file_put_contents('error.log', $e->getMessage() . "\n", FILE_APPEND);
    $output = [
        'error' => [
            'message' => $e->getMessage()
        ]
    ];
}

header('Content-Type: application/json');
echo json_encode($output);
