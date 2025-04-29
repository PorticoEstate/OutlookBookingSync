<?php

namespace App\Controller;

class ResourceMappingController
{
	public function getMapping($request, $response, $args)
	{
		$db = $request->getAttribute('db');
		// Example query, adjust table/fields as needed
		$stmt = $db->query('SELECT * FROM bb_resource_outlook_item');
		$data = $stmt->fetchAll();
		$response->getBody()->write(json_encode($data));
		return $response->withHeader('Content-Type', 'application/json');
	}
}
