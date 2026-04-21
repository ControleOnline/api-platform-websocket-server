## Escopo
- Modulo de infraestrutura websocket da API.
- Cobre servidor, clientes, controller e utilitarios de comunicacao em tempo real.

## Quando usar
- Prompts sobre WebSocket, comandos do servidor realtime, envio de mensagem para devices e infraestrutura de socket.

## Limites
- Nao mover regra de negocio de pedido, pagamento ou fila para este modulo.
- `websocket-server` deve transportar eventos e comandos, nao decidir a regra principal.
