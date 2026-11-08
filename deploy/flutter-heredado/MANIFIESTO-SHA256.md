# Manifiesto de huellas (correctivos v6)

Huella base = la de produccion impresa por las inspecciones (12 primeros hex, o 64 donde C-3 las imprime completas). [X] = el texto base se reconstruyo y su SHA-256 coincide con el de produccion, de modo que la huella resultante es reproducible. [R] = solo hay texto literal parcial: el parche se redacta con las lineas reales impresas y se verifica en la ventana con `patch --dry-run -F0` (cero desplazamiento, cero fuzz) sobre la copia respaldada; la huella resultante se anota entonces.

| Lote | Cambio | Archivo de produccion | Huella base (SHA-256) | Base | Huella tras el parche | Huella del parche |
|---|---|---|---|---|---|---|
| 1 | K2a+K2c+K2d+K2e | `backend/router/EmpresaRouter.php` | `a50a3c1c2a78` | exacta [X] | `0c710e8ef8e5f367dcab54ba57a39083e9e2c997a799359a41062137290177b7` | `f5df3f988cfc6dd203f7764877dd7c01ea8a826a3ff49aaec72e2a775e91e54c` |
| 1 | K2 (archivo nuevo) | `backend/lib/empresa_sanitizer.php` (nuevo) | — | — | `cf680885963d6d518e09995f9b1f4b2f7e4105a7aa7f0ec64db319c36b59f77e` | — |
| 2 | F-2 (K1) | `backend/router/PowerBIRouter.php` | `8ee4f7ee7529` | exacta [X] | `0a174050d7d7f263e6184268b80e56f4bb72aa257e08d3abe26f24b29c85abc2` | `b1639b70e13e9ceedec1c850e2b110d3f15ed38ff2c01d4ef27555e027e63667` |
| 2 | F-2 (archivo nuevo) | `backend/lib/powerbi_authz.php` (nuevo) | — | — | `4137cff3cab2d2390dbd82e73500e37d432e9f259b1f0931da8f163d5350e3a1` | — |
| 3 | F-3 (A1) | `backend/router/UserRouter.php` | `60b011d54265` | literal [R] | no publicable (base solo literal; se calcula sobre la copia respaldada) | `3b620a075a2937e04029c62de6a9acdde6dddb04d7f83ff978766c6ed7046307` |
| 4 | F-4 | `backend/router/AsignacionRouter.php` | `4b4962ced9f0` | exacta [X] | `6c444d3686f51ae6e3e857ddbdc0d6c2ed6faeea5b2de0e28cbab39a8930a189` | `53eb08c79643ae3fe7f291f88148c2e81546756620dc9636f8a02af01e90e244` |
| 4 | F-4 | `backend/router/EmpresaUsuarioRouter.php` | `c4433d63c213` | exacta [X] | `a0cfb254dcf5b40529480daea874fd51a8d00ec5ca96ed3bb2821f1eee064fb4` | `53eb08c79643ae3fe7f291f88148c2e81546756620dc9636f8a02af01e90e244` |
| 4 | F-5a | `backend/router/EmpresaRouter.php` | `a50a3c1c2a78` | exacta [X] | `3ce63137507cc69e0db01f76f1b4a422b0e020ae8618400d0c79c107ac20faab` | `a75ed9028ffcef066c2ff927875ee838bab82f84a8f23ee1dd2adf76ccc0bc58` |
| 4 | F-5a sobre K2 | `backend/router/EmpresaRouter.php` | `a50a3c1c2a78` | exacta [X] | `3b0e381c2869e10d523b800e8c621fe09f6b06e8f18b0dfa8147b98b65263af1` | `7733c86d2049e37f113f75b8218fb244e556fe296f0d17b103f873200ac3d530` |
| 4 | F-5b | `backend/router/ReporteRouter.php` | `14406e81fdf3` | literal [R] | no publicable (base solo literal; se calcula sobre la copia respaldada) | `777cd5662d94fed220aedc87ee1b3dc4e12030b45f30b9d75eaf01d0bef32991` |
| 4 | F-5c | `backend/router/EmpresaReporteUsuarioRouter.php` | `b854eaa4d40f` | literal [R] | no publicable (base solo literal; se calcula sobre la copia respaldada) | `86f423bfc88add01c44007eda3d788b033a22cbcf38c48f2f1257bcbe386dc42` |
| 4 | F-5d | `backend/controllers/ReporteController.php` | `880d799275ca0646766d254b6755e357fdff4c288ac48107eca1689a63afcb89` | literal [R] | no publicable (base solo literal; se calcula sobre la copia respaldada) | `55d369fbc2f4e3e432aa9afdf37fc3fb5c502cd6300e54e5c3f4006008bb8e8d` |
| 4 | F-5e | `backend/models/ReporteModel.php` | `18b2e253a955a25373811dcd1c3d29aa90a124abdf59405c564cffd5e69fcc41` | literal [R] | no publicable (base solo literal; se calcula sobre la copia respaldada) | `1687a0f5afe9b392c96b052c1a1130ef36a441fdb96bb6a8f0dace68a7b4fd35` |
| 4 | F-5f | `backend/models/EmpresaModel.php` | `a645a5e864e9afa0510b126d49a25e4af6ec82aff94fd49982206503cab2839d` | literal [R] | no publicable (base solo literal; se calcula sobre la copia respaldada) | `288401c912d8727b010edf54d623b16558023ec11db886ae0582254e6a66d50f` |
| 4 | F-5g | `backend/models/EmpresaReporteUsuarioModel.php` | `2aa5b626a9faf22dcb3b58f3b40e8b7d12585cab3d981b23dc44edd5b6db914d` | literal [R] | no publicable (base solo literal; se calcula sobre la copia respaldada) | `f8039867f5a5c1b47ea36ae1094c9a041e21f35bc382b49d06d3a78c1b708314` |
| 4 | F-10 | `backend/router/auth.php` | `a4f063a53db4` | literal [R] | no publicable (base solo literal; se calcula sobre la copia respaldada) | `07fd3d6bb9e053611fc3ee8134dcd56f425297c4da5b543f9876bca29bd02b97` |
