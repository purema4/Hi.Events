import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {apiKeyClient} from "../api/api-key.client.ts";
import {GET_API_KEYS_QUERY_KEY} from "../queries/useGetApiKeys.ts";
import {useGetMe} from "../queries/useGetMe.ts";

export const useDeleteApiKey = () => {
    const queryClient = useQueryClient();
    const {data: me} = useGetMe();

    return useMutation({
        mutationFn: ({apiKeyId}: {
            apiKeyId: IdParam
        }) => apiKeyClient.delete(me?.account_id, apiKeyId),

        onSuccess: () => queryClient.invalidateQueries({queryKey: [GET_API_KEYS_QUERY_KEY]})
    });
}
