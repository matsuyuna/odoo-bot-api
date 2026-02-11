<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class BotProductoOldController extends Controller
{

    
    public function buscar(Request $request)
    {
        $nombre = $request->query('nombre', '');
    
        if (empty($nombre)) {
            return response()->json(['error' => 'Falta el parámetro "nombre"'], 400);
        }
        
        // Variables de entorno
        $url_base = rtrim(env('ODOO_URL'), '/');
        $db = env('ODOO_DB');
        $username = env('ODOO_USERNAME');
        $password = env('ODOO_PASSWORD');
        
        // 1. Hacer login para obtener uid
        $url_common = $url_base . '/xmlrpc/2/common';
        
        $loginXml = <<<XML
    <?xml version="1.0"?>
    <methodCall>
      <methodName>login</methodName>
      <params>
        <param><value><string>{$db}</string></value></param>
        <param><value><string>{$username}</string></value></param>
        <param><value><string>{$password}</string></value></param>
      </params>
    </methodCall>
    XML;
    
        $loginResponse = Http::withHeaders([
            'Content-Type' => 'text/xml',
        ])->withBody($loginXml, 'text/xml')->post($url_common);
    
        // Parsear uid de la respuesta XML
        preg_match('/<int>(\d+)<\/int>/', $loginResponse->body(), $matches);
        $uid = $matches[1] ?? null;
    
        if (!$uid) {
            return response()->json(['error' => 'Error de autenticación en Odoo'], 500);
        }
    
        // 2. Ejecutar search_read para productos que coincidan con $nombre
        $url_object = $url_base . '/xmlrpc/2/object';
    
        // XML para search_read con filtro ilike sobre 'name'
        $searchXml = <<<XML
        <?xml version="1.0"?>
        <methodCall>
          <methodName>execute_kw</methodName>
          <params>
            <param><value><string>{$db}</string></value></param>
            <param><value><int>{$uid}</int></value></param>
            <param><value><string>{$password}</string></value></param>
            <param><value><string>product.product</string></value></param>
            <param><value><string>search_read</string></value></param>
            <param>
              <value>
                <array>
                  <data>
                    <value>
                      <array>
                        <data>
                          <!-- OR condition -->
                          <value><string>|</string></value>
                          <!-- name ilike -->
                          <value>
                            <array>
                              <data>
                                <value><string>name</string></value>
                                <value><string>ilike</string></value>
                                <value><string>{$nombre}</string></value>
                              </data>
                            </array>
                          </value>
                          <!-- default_code ilike -->
                          <value>
                            <array>
                              <data>
                                <value><string>default_code</string></value>
                                <value><string>ilike</string></value>
                                <value><string>{$nombre}</string></value>
                              </data>
                            </array>
                          </value>
                        </data>
                      </array>
                    </value>
                  </data>
                </array>
              </value>
            </param>
            <param>
              <value>
                <struct>
                  <member>
                    <name>fields</name>
                    <value>
                      <array>
                        <data>
                          <value><string>id</string></value>
                          <value><string>name</string></value>
                          <value><string>qty_available</string></value>
                          <value><string>default_code</string></value>
                        </data>
                      </array>
                    </value>
                  </member>
                </struct>
              </value>
            </param>
          </params>
        </methodCall>
        XML;
        
    
        $searchResponse = Http::withHeaders([
            'Content-Type' => 'text/xml',
        ])->withBody($searchXml, 'text/xml')->post($url_object);
    
        $xmlBody = $searchResponse->body();
    
        // Convertir XML a JSON
        $xml = simplexml_load_string($xmlBody);
    
        if (!$xml) {
            return response()->json(['error' => 'Error al parsear la respuesta XML'], 500);
        }
    
        $json = json_decode(json_encode($xml), true);
    
        // Extraer productos del XML-RPC
        $productos = [];
    
        $values = data_get($json, 'params.param.value.array.data.value', []);
    
        foreach ($values as $producto) {
            $datos = data_get($producto, 'struct.member', []);
            $productoLimpio = [];
            foreach ($datos as $item) {
                $key = $item['name'];
                $value = $item['value'];
                if (isset($value['string'])) {
                    $productoLimpio[$key] = $value['string'];
                } elseif (isset($value['int'])) {
                    $productoLimpio[$key] = (int) $value['int'];
                } elseif (isset($value['double'])) {
                    $productoLimpio[$key] = (float) $value['double'];
                } else {
                    $productoLimpio[$key] = $value;
                }
            }
            $productos[] = $productoLimpio;
        }
    
        return response()->json($productos);
    }
    
    
}
