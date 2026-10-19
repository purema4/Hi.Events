import {useMutation, useQueryClient} from "@tanstack/react-query";
import {CreateApiKeyRequest} from "../types.ts";
import {apiKeyClient} from "../api/api-key.client.ts";
import {GET_API_KEYS_QUERY_KEY} from "../queries/useGetApiKeys.ts";
import {useGetMe} from "../queries/useGetMe.ts";

export const useCreateApiKey = () => {
    const queryClient = useQueryClient();
    const {data: me} = useGetMe();

    return useMutation({
        mutationFn: ({apiKeyData}: {
            apiKeyData: CreateApiKeyRequest
        }) => apiKeyClient.create(me?.account_id, apiKeyData),

        onSuccess: () => queryClient.invalidateQueries({queryKey: [GET_API_KEYS_QUERY_KEY]})
    });
}
