<x-app-shell title="Operations wall" wide>
    <x-operations::board
        :endpoint="$endpoint"
        :user-key="$userKey"
        :sites="$sites"
        :scope="$scope" />
</x-app-shell>
