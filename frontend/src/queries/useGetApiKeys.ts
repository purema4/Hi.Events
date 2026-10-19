import {useQuery} from "@tanstack/react-query";
import {ApiKey, GenericDataResponse, IdParam} from "../types.ts";
import {apiKeyClient} from "../api/api-key.client.ts";
import {useGetMe} from "./useGetMe.ts";

export const GET_API_KEYS_QUERY_KEY = 'getApiKeys';

export const useGetApiKeys = () => {
    const meQuery = useGetMe();
    const accountId = meQuery.data?.account_id as IdParam;

    return useQuery<GenericDataResponse<ApiKey[]>>({
            queryKey: [GET_API_KEYS_QUERY_KEY, accountId],
            enabled: meQuery.isFetched,
            queryFn: async () => await apiKeyClient.all(accountId),
        }
    )
}
