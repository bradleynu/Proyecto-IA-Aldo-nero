<?php
// Muestra todos los errores para facilitar la depuración
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Cabeceras CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// --- CONFIGURACIÓN PARA VERTEX AI ---
// Carga la API Key de forma segura desde una variable de entorno
$apiKey = getenv('GEMINI_API_KEY'); 

if (!$apiKey) {
    echo json_encode(["error" => "La variable de entorno GEMINI_API_KEY no está configurada."]);
    exit;
}

// Recibe los datos de JavaScript
$input = json_decode(file_get_contents("php://input"), true);
if (!$input) {
    echo json_encode(["error" => "No se recibieron datos o el JSON es inválido."]);
    exit;
}

$baseId = $input["baseProductId"] ?? "";
$prefs = $input["preferences"] ?? [];

// Carga el catálogo de productos
$catalogPath = __DIR__ . "/products.json";
$catalogo = json_decode(file_get_contents($catalogPath), true);

// Busca el producto base en el catálogo
$baseProduct = null;
foreach ($catalogo as $p) {
    if ($p["id"] === $baseId) {
        $baseProduct = $p;
        break;
    }
}
if (!$baseProduct) {
    echo json_encode(["error" => "Producto base no encontrado en el catálogo."]);
    exit;
}

// Construcción del Prompt para Gemini
$prompt = "Tu rol: Eres un estilista de moda experto. Tu objetivo es crear un outfit completo y coherente a partir de un catálogo.\n\n" .
          "Tarea: Diseña un outfit completo y armónico.\n\n" .
          "Producto base (prenda que ya tengo): " . json_encode($baseProduct, JSON_UNESCAPED_UNICODE) . "\n\n" .
          "Catálogo de prendas disponibles: " . json_encode($catalogo, JSON_UNESCAPED_UNICODE) . "\n\n" .
          "Preferencias del usuario: Ocasión: " . ($prefs["occasion"] ?? "cualquiera") . ", Estilo: " . ($prefs["style"] ?? "cualquiera") . "\n\n" .
          "Reglas CRÍTICAS:\n" .
          "1. El outfit debe ser coherente. Todas las prendas sugeridas deben combinar entre sí.\n" .
          "2. Selecciona MÁXIMO UNA prenda de la categoría 'prendas superiores', UNA de 'prendas inferiores' y UNA de 'zapatos'.\n" .
          "3. NO INCLUYAS el producto base en tus sugerencias.\n" .
          "4. Responde ÚNICAMENTE con un JSON válido, sin ningún texto o formato adicional. El JSON debe tener este formato: {\"suggestions\": [\"id_producto_1\",\"id_producto_2\"]}";

// --- LLAMADA A LA API DE VERTEX AI ---
// ¡ESTA ES LA LÍNEA MÁS IMPORTANTE Y LA QUE HEMOS CORREGIDO!
// Apunta a la versión estable de la API y al modelo de texto correcto.
$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.0-pro:generateContent?key=" . $apiKey;

$data = ["contents" => [["parts" => [["text" => $prompt]]]]];
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Para desarrollo local

$response = curl_exec($ch);
if (curl_errno($ch)) {
    echo json_encode(["error" => "Error de cURL: " . curl_error($ch)]);
    curl_close($ch);
    exit;
}
curl_close($ch);

$resObj = json_decode($response, true);

if (isset($resObj["error"])) {
    $errorMessage = $resObj["error"]["message"] ?? "Error desconocido de la API de Gemini.";
    echo json_encode(["error" => "Error de la API de Gemini: " . $errorMessage]);
    exit;
}

// La respuesta de la API de Vertex AI a veces puede venir sin 'candidates' si el prompt es bloqueado por seguridad.
if (!isset($resObj["candidates"][0]["content"]["parts"][0]["text"])) {
     echo json_encode(["error" => "La API no devolvió contenido. Revisa el 'raw output' para más detalles.", "raw" => $resObj]);
     exit;
}
$content = $resObj["candidates"][0]["content"]["parts"][0]["text"];

$ids = [];
if ($content) {
    $jsonString = $content;
    if (preg_match('/\{.*\}/s', $content, $matches)) {
        $jsonString = $matches[0];
    }
    $parsed = json_decode($jsonString, true);
    if ($parsed && isset($parsed["suggestions"]) && is_array($parsed["suggestions"])) {
        $ids = $parsed["suggestions"];
    }
}

$suggestions = array_values(array_filter($catalogo, fn($p) => in_array($p["id"], $ids)));

echo json_encode([
    "baseProduct" => $baseProduct,
    "suggestions" => $suggestions,
    "raw" => ["model_output" => $content, "full_response" => $resObj]
], JSON_UNESCAPED_UNICODE);

?>