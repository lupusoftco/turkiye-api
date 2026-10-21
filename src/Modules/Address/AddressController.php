<?php

declare(strict_types=1);

namespace TurkiyeApi\Modules\Address;

use TurkiyeApi\Http\ApiException;
use TurkiyeApi\Http\Request;
use TurkiyeApi\Http\Response;

final class AddressController
{
    private const SOURCE = 'turkey-neighbourhoods by Murat Gözel (MIT)';
    private const DAY = 86400;

    private AddressRepository $repo;

    public function __construct(AddressRepository $repo)
    {
        $this->repo = $repo;
    }

    public function cities(Request $request): Response
    {
        $this->ensureData();
        return Response::data($this->repo->cities(), ['count' => 81, 'source' => self::SOURCE], self::DAY);
    }

    public function city(Request $request): Response
    {
        $city = $this->findCity($request->route('city'));
        $districts = $this->repo->districts($city['code']);
        return Response::data($city + ['districts' => $districts], ['source' => self::SOURCE], self::DAY);
    }

    public function districts(Request $request): Response
    {
        $city = $this->findCity($request->route('city'));
        $districts = $this->repo->districts($city['code']);
        return Response::data($districts, ['city' => $city, 'count' => count($districts), 'source' => self::SOURCE], self::DAY);
    }

    public function neighbourhoods(Request $request): Response
    {
        $this->ensureData();
        $district = $this->repo->district((int) $request->route('id'));
        if ($district === null) {
            throw ApiException::notFound('No district with this id.');
        }
        $list = $this->repo->neighbourhoods($district['id']);
        return Response::data($list, ['district' => $district, 'count' => count($list), 'source' => self::SOURCE], self::DAY);
    }

    public function cityDistrictNeighbourhoods(Request $request): Response
    {
        $city = $this->findCity($request->route('city'));
        $id = $this->repo->districtBySlug($city['code'], $request->route('district'));
        if ($id === null) {
            throw ApiException::notFound("No district with this name in {$city['name']}.");
        }
        $list = $this->repo->neighbourhoods($id);
        return Response::data($list, ['district' => $this->repo->district($id), 'count' => count($list), 'source' => self::SOURCE], self::DAY);
    }

    public function postalCode(Request $request): Response
    {
        $this->ensureData();
        $code = $request->route('code');
        $list = $this->repo->byPostalCode($code);
        if ($list === []) {
            throw ApiException::notFound("No neighbourhood uses postal code $code.");
        }
        return Response::data($list, ['postal_code' => $code, 'count' => count($list), 'source' => self::SOURCE], self::DAY);
    }

    public function search(Request $request): Response
    {
        $this->ensureData();
        $q = trim($request->required('q'));
        if (mb_strlen($q) < 2 || mb_strlen($q) > 100) {
            throw ApiException::badRequest('`q` must be 2–100 characters.', 'q');
        }
        $cityCode = null;
        if (($cityParam = $request->input('city')) !== null && $cityParam !== '') {
            $cityCode = $this->findCity($cityParam)['code'];
        }
        $districtParam = $request->input('district');
        $districtId = null;
        if ($districtParam !== null && $districtParam !== '') {
            if (ctype_digit($districtParam)) {
                $districtId = (int) $districtParam;
            } elseif ($cityCode !== null) {
                $districtId = $this->repo->districtBySlug($cityCode, $districtParam);
                if ($districtId === null) {
                    throw ApiException::notFound('No district with this name in the given city.');
                }
            } else {
                throw ApiException::badRequest('Filtering by district name requires `city`; or pass the district id.', 'district');
            }
        }
        $limit = $request->int('limit', 20, 1, 50);
        $results = $this->repo->search($q, $cityCode, $districtId, $limit);
        return Response::data($results, ['query' => $q, 'count' => count($results), 'source' => self::SOURCE], 3600);
    }

    public function distance(Request $request): Response
    {
        $from = $this->findCity($request->required('from'));
        $to = $this->findCity($request->required('to'));
        $km = $this->repo->distance($from['code'], $to['code']);
        if ($km === null) {
            throw ApiException::notFound('Distance is not available for this pair.');
        }
        return Response::data([
            'from' => $from,
            'to' => $to,
            'km' => $km,
        ], ['note' => 'Road distance between province centres.', 'source' => self::SOURCE], self::DAY);
    }

    /** @return array{code: string, name: string, slug: string} */
    private function findCity(string $value): array
    {
        $this->ensureData();
        $city = $this->repo->city($value);
        if ($city === null) {
            throw ApiException::notFound('No province with this plate code or name.');
        }
        return $city;
    }

    private function ensureData(): void
    {
        if (!$this->repo->isImported()) {
            throw new ApiException(503, 'data-not-imported', 'Address data is not imported yet. Run `php bin/setup.php`.');
        }
    }
}
