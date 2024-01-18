<?php

namespace Lupennat\NestedMany\Fields;

use Laravel\Nova\Http\Requests\NovaRequest;
use Lupennat\NestedMany\Http\Controllers\NestedController;
use Lupennat\NestedMany\Http\Requests\NestedResourceUpdateOrUpdateAttachedRequest;

trait NestedRecursive
{
    /**
     * The field's preloaded resources.
     */
    public $resources = [];

    /**
     * Generate resources from parent.
     */
    public function generateResourcesFromParent(NovaRequest $request, $index): void
    {
        $resources = [];

        $parent = $request->nestedChildren[$index] ?? null;

        if ($parent) {
            $keyName = $request->model()->getKeyName();
            $children = $parent[$this->validationKey()] ?? [];

            if (count($children)) {
                $oldResourceName = $request->route('resource');
                $request->route()->setParameter('resource', $this->resourceName);
                $newRequest = NovaRequest::createFrom($request);

                $updateRequest = NestedResourceUpdateOrUpdateAttachedRequest::createFrom($newRequest->replace([
                    'nestedChildren' => $children,
                    'viaResource' => $oldResourceName,
                    'viaResourceId' => $parent[$keyName] ?? null,
                    'viaRelationship' => $this->attribute,
                ]));

                $resources = (new NestedController())->updateResources($updateRequest)->getData(true)['resources'] ?? [];

                $request->route()->setParameter('resource', $oldResourceName);
            }
        }

        $this->resources = $resources;
    }

    /**
     * Generate resources from parent.
     */
    public function generateResourcesFromResource(NovaRequest $request, $resource): void
    {

        $resources = [];

        foreach($resource->getRelations() as $name => $relatedResources) {
            $oldResourceName = $request->route('resource');
            $request->route()->setParameter('resource', $this->resourceName);

            $newRequest = NovaRequest::createFrom($request);

            $updateRequest = NestedResourceUpdateOrUpdateAttachedRequest::createFrom($newRequest->replace([
                'viaResource' => $oldResourceName,
                'viaResourceId' => $resource->getKey() ?? null,
                'viaRelationship' => $this->attribute,
            ]));

            $resourceClass = $updateRequest->resource();

            $resources = collect($relatedResources)->mapInto($resourceClass)->map(function ($resource) use ($updateRequest) {
                $updateRequest['editing'] = 'true';
                $updateRequest['editMode'] = !$resource->resource->exists && !$resource->resource->isNestedDefault() ? 'create' : 'update';
                $updateRequest['nestedManagedByParent'] = 'true';

                // readonly is resolved using app request on jsonserialize
                // we need to serialize for each element that way editMode is preserved
                return json_decode(json_encode(!$resource->resource->exists && !$resource->resource->isNestedDefault() ?
                $resource->serializeForNestedCreate($updateRequest) :
                $resource->serializeForNestedUpdate($updateRequest)), true);
            })->toArray();

            $request->route()->setParameter('resource', $oldResourceName);
        }

        $this->resources = $resources;
    }

    public function getValidationAttributeNamesFromParent(NovaRequest $request, string $resourceName, string $attribute, int $index): array
    {
        $validationNames = [];

        $this->executeCallbackWithRequestAdaptedFromParent(
            $request,
            $resourceName,
            $attribute,
            $index,
            function (NovaRequest $request) use (&$validationNames) {
                $validationNames = $this->getValidationAttributeNames($request);
            }
        );

        return $validationNames;
    }

    public function getCreationRulesFromParent(NovaRequest $request, string $resourceName, string $attribute, int $index): array
    {
        $rules = [];

        $this->executeCallbackWithRequestAdaptedFromParent(
            $request,
            $resourceName,
            $attribute,
            $index,
            function (NovaRequest $request) use (&$rules) {
                $rules = $this->getCreationRules($request);
            }
        );

        return $rules;
    }

    public function getUpdateRulesFromParent(NovaRequest $request, string $resourceName, string $attribute, int $index): array
    {
        $rules = [];

        $this->executeCallbackWithRequestAdaptedFromParent(
            $request,
            $resourceName,
            $attribute,
            $index,
            function (NovaRequest $request) use (&$rules) {
                $rules = $this->getUpdateRules($request);
            }
        );

        return $rules;
    }

    protected function executeCallbackWithRequestAdaptedFromParent(NovaRequest $request, string $resourceName, string $attribute, int $index, callable $callback)
    {
        $parent = $request->{$attribute}[$index] ?? null;

        if ($parent) {
            $keyName = $request->model()->getKeyName();
            $children = $parent[$this->validationKey()] ?? [];

            if (count($children)) {
                $oldResourceName = $request->route('resource');
                $oldResourceId = $request->route('resourceId');
                $resourceId = $parent[$keyName] ?? null;

                $request->route()->setParameter('resource', $resourceName);

                if (!$resourceId) {
                    $request->route()->forgetParameter('resourceId');
                } else {
                    $request->route()->setParameter('resourceId', $resourceId);
                }

                $newRequest = NovaRequest::createFrom($request);

                $updateRequest = NovaRequest::createFrom($newRequest->replace([
                    $this->attribute => $children,
                ]));

                $callback($updateRequest);
                $request->route()->setParameter('resource', $oldResourceName);

                if (!$oldResourceId) {
                    $request->route()->forgetParameter('resourceId');
                } else {
                    $request->route()->setParameter('resourceId', $oldResourceId);
                }
            }
        }
    }
}
