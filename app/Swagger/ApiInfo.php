<?php

namespace App\Swagger;

use OpenApi\Attributes as OA;

#[OA\Info(
    title: 'API do Sistema de Vendas',
    version: '1.0.0',
    description: 'Documentação da API de gestão de produtos, utilizadores, categorias e vendas.'
)]
#[OA\Server(
    url: '/',
    description: 'Mesmo servidor e porta da documentação Swagger'
)]
class ApiInfo
{
}
