<?php

namespace App\Swagger;

use OpenApi\Attributes as OA;

#[OA\Info(
    title: 'API do Sistema de Vendas',
    version: '1.0.0',
    description: 'Documentação da API para gestão de produtos, utilizadores, categorias e vendas.'
)]

#[OA\Server(
    url: '/',
    description: 'Mesmo servidor e porta da documentação Swagger'
)]

#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'JWT',
    description: 'Token de autenticação Bearer'
)]

class ApiInfo
{
}
