# Address data

`cities.csv`, `neighbourhoods.csv` and `distances.csv` are generated from the
[turkey-neighbourhoods](https://github.com/muratgozel/turkey-neighbourhoods) npm package
(v4.0.3) by Murat Gözel, released under the MIT licence (see `LICENSE-turkey-neighbourhoods`).

To refresh from a newer version:

```bash
npm pack turkey-neighbourhoods && tar xzf turkey-neighbourhoods-*.tgz
php tools/build-address-data.php package/src/data data/address
php bin/setup.php
```
